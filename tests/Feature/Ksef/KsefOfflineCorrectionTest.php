<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceMutationPolicy;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefEcdsaSignatureConverter;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefOfflineDeliveryPolicy;
use Modules\Ksef\Services\KsefOfflineInvoiceSubmissionService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Modules\Ksef\Services\KsefOfflinePresentationDataExtractor;
use Modules\Ksef\Services\KsefOfflinePresentationPdfRenderer;
use Modules\Ksef\Services\KsefOfflineSubmissionIntegrityService;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationEngine;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationQueryService;
use Modules\Ksef\Services\PolishBusinessDayCalendar;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefOfflineCorrectionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    private CarbonImmutable $instant;

    private array $certificateFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->certificateFixture = KsefCertificateFixtureFactory::offlineEc();
        $this->instant = CarbonImmutable::createFromTimestamp($this->certificateFixture['valid_from'], 'UTC')->addHour();
        $this->travelTo($this->instant);
        config()->set('ksef.invoice_submission_enabled', true);
    }

    #[DataProvider('procedures')]
    public function test_issuance_finalizes_correction_freezes_own_kor_and_cryptographic_links_without_transport(string $procedure): void
    {
        [$root, $correction] = $this->scenario();
        $this->evidence($procedure);
        $issuance = $this->issue($correction, $procedure);
        $xml = $issuance->payload_xml;
        $xpath = $this->ksefXpath($xml);
        $this->assertTrue($correction->fresh()->isFinalized());
        $this->assertSame($correction->id, $issuance->invoice_id);
        $this->assertSame($procedure, $issuance->procedure->value);
        $this->assertSame('KOR', $this->ksefValue($xpath, '/fa:Faktura/fa:Fa/fa:RodzajFaktury'));
        $this->assertSame($correction->number, $this->ksefValue($xpath, '/fa:Faktura/fa:Fa/fa:P_2'));
        $this->assertSame($this->instant->setTimezone('Europe/Warsaw')->toDateString(), $issuance->issue_date->toDateString());
        $this->assertSame($this->instant->getTimestamp(), $issuance->issued_at->getTimestamp());
        $this->assertSame($root->ksefSubmissions()->firstOrFail()->ksef_number, $this->ksefValue($xpath, '/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej'));
        $this->assertSame(base64_encode(hash('sha256', $xml, true)), $issuance->invoice_hash);
        $this->assertSame(strlen($xml), $issuance->invoice_size);
        $hash = rtrim(strtr($issuance->invoice_hash, '+/', '-_'), '=');
        $this->assertStringEndsWith('/'.$hash, $issuance->invoice_verification_url);
        $this->assertStringContainsString('/'.$hash.'/', $issuance->certificate_verification_url);
        $lastSlash = strrpos($issuance->certificate_verification_url, '/');
        $signature = substr($issuance->certificate_verification_url, $lastSlash + 1);
        $raw = base64_decode(strtr($signature, '-_', '+/'), true);
        $this->assertSame(1, openssl_verify(
            substr($issuance->certificate_verification_url, 8, $lastSlash - 8),
            app(KsefEcdsaSignatureConverter::class)->rawToDer($raw),
            $this->certificateFixture['certificate'],
            OPENSSL_ALGO_SHA256,
        ));
        $this->assertSame($procedure === 'offline24', $issuance->latarnia_trigger_event_id === null);
        $this->assertStringNotContainsString('<Faktura', DB::table('ksef_offline_issuances')->where('id', $issuance->id)->value('payload_xml'));
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $correction->id]);
        app(KsefOfflineSubmissionIntegrityService::class)->assertIssuance($issuance, $correction->fresh());
        Http::assertNothingSent();
    }

    public static function procedures(): array
    {
        return [['offline24'], ['planned_unavailability'], ['failure']];
    }

    #[DataProvider('sourceCases')]
    public function test_exact_source_acceptance_and_provenance_matrix(string $source, ?string $error): void
    {
        [$root, $correction] = $this->scenario(source: $source);
        if ($error !== null) {
            $this->assertError($error, fn () => $this->issue($correction));
            $this->assertFalse($correction->fresh()->isFinalized());
            $this->assertDatabaseMissing('ksef_offline_issuances', ['invoice_id' => $correction->id]);
        } else {
            $issuance = $this->issue($correction);
            $xml = $issuance->payload_xml;
            if ($source === 'outside') {
                $this->assertStringContainsString('<NrKSeFN>1</NrKSeFN>', $xml);
                $this->assertStringNotContainsString('<NrKSeF>', $xml);
                $this->assertStringNotContainsString('<NrKSeFFaKorygowanej>', $xml);
            } else {
                $this->assertStringContainsString('<NrKSeF>1</NrKSeF>', $xml);
                $this->assertStringContainsString($root->ksefSubmissions()->where('status', 'accepted')->firstOrFail()->ksef_number, $xml);
            }
        }
        Http::assertNothingSent();
    }

    public static function sourceCases(): array
    {
        return [
            ['online', null], ['offline_accepted', null], ['outside', null],
            ['offline', 'ksef_fa3_correction_source_ksef_unresolved'],
            ['wrong_environment', 'ksef_fa3_correction_source_ksef_environment_mismatch'],
            ['ambiguous', 'ksef_fa3_correction_source_ksef_ambiguous'],
            ['rejected', 'ksef_fa3_correction_source_ksef_not_accepted'],
            ['processing', 'ksef_fa3_correction_source_ksef_not_accepted'],
            ['uncertain', 'ksef_fa3_correction_source_ksef_not_accepted'],
        ];
    }

    public function test_previous_offline_correction_requires_acceptance_before_next_issuance(): void
    {
        [$root, $first] = $this->scenario();
        $firstIssuance = $this->issue($first);
        $next = $this->currentDate($this->issueKsefFinancialCorrection($root, 3));
        $this->assertError('ksef_fa3_correction_previous_ksef_not_accepted', fn () => $this->issue($next));
        $this->assertFalse($next->fresh()->isFinalized());
        $accepted = $this->acceptKsefDocument($first, KsefEnvironment::Test);
        $accepted->forceFill(['offline_issuance_id' => $firstIssuance->id, 'invoicing_mode' => KsefInvoicingMode::Offline])->save();
        $this->assertSame($next->id, $this->issue($next)->invoice_id);
    }

    #[DataProvider('sourceRaces')]
    public function test_source_changes_after_generation_rollback_finalization_and_issuance(string $race): void
    {
        [$root, $correction] = $this->scenario(source: $race === 'provenance' ? 'outside' : 'online');
        $previous = null;
        if ($race === 'previous') {
            $first = $this->issue($correction);
            $previous = $this->acceptKsefDocument($correction, KsefEnvironment::Test);
            $previous->forceFill(['offline_issuance_id' => $first->id])->save();
            $correction = $this->currentDate($this->issueKsefFinancialCorrection($root, 3));
        }
        $selects = 0;
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$selects, &$changed, $race, $root, $previous): void {
            if ($changed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'ksef_offline_certificate_selections')) {
                return;
            }
            if (++$selects !== 2) {
                return;
            }
            $changed = true;
            if ($race === 'provenance') {
                DB::table('ksef_invoice_provenances')->where('invoice_id', $root->id)->delete();
            } elseif ($race === 'seller') {
                $root->forceFill(['seller_snapshot' => array_merge($root->seller_snapshot, ['tax_id' => '5260250995'])])->saveQuietly();
            } else {
                $submission = $race === 'previous' ? $previous : $root->ksefSubmissions()->firstOrFail();
                $values = match ($race) {
                    'identity' => ['id' => $submission->id + 1000],
                    'environment' => ['environment' => 'demo'],
                    default => ['ksef_number' => $this->validKsefNumber('9876543210', '000000000999')],
                };
                DB::table('ksef_invoice_submissions')->where('id', $submission->id)->update($values);
            }
        });
        $this->assertError('ksef_offline_correction_source_changed', fn () => $this->issue($correction));
        $this->assertTrue($changed);
        $this->assertFalse($correction->fresh()->isFinalized());
        $this->assertDatabaseMissing('ksef_offline_issuances', ['invoice_id' => $correction->id]);
        Http::assertNothingSent();
    }

    public static function sourceRaces(): array
    {
        return [['number'], ['identity'], ['provenance'], ['environment'], ['seller'], ['previous']];
    }

    #[DataProvider('procedures')]
    public function test_exact_frozen_transport_acceptance_own_number_upo_and_single_qr(string $procedure): void
    {
        Event::fake([KsefInvoiceAccepted::class]);
        [$root, $correction] = $this->scenario();
        $rootNumber = $root->ksefSubmissions()->firstOrFail()->ksef_number;
        $this->evidence($procedure);
        $issuance = $this->issue($correction, $procedure);
        $presentation = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);
        $correction->forceFill(['additional_information_text' => 'LATER MUTABLE VALUE'])->saveQuietly();
        $this->assertEquals($presentation, app(KsefOfflinePresentationDataExtractor::class)->extract($issuance->fresh()));
        KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test, 'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_OFFLINE_CORRECTION_TOKEN', 'access_token' => 'FAKE_OFFLINE_CORRECTION_ACCESS',
            'access_token_valid_until' => now()->addHour(), 'refresh_token' => 'FAKE_OFFLINE_CORRECTION_REFRESH',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
        $fake = new KsefOnlineSessionApiFake;
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        Http::fake(fn (Request $request) => $fake($request));
        $submission = app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($correction, $issuance);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame(true, $fake->sendPayload['offlineMode']);
        $this->assertArrayNotHasKey('hashOfCorrectedInvoice', $fake->sendPayload);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame($issuance->payload_xml, $submission->payload_xml);
        $key = $fake->privateKey->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')
            ->decrypt(base64_decode($fake->openPayload['encryption']['encryptedSymmetricKey'], true));
        $sentXml = openssl_decrypt(base64_decode($fake->sendPayload['encryptedInvoiceContent'], true), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, base64_decode($fake->openPayload['encryption']['initializationVector'], true));
        $this->assertSame($issuance->payload_xml, $sentXml);
        $this->assertSame($issuance->invoice_hash, $fake->sendPayload['invoiceHash']);
        $this->assertSame($issuance->invoice_size, $fake->sendPayload['invoiceSize']);
        $this->assertSame($issuance->issued_at->getTimestamp(), $submission->generated_at->getTimestamp());
        $number = KsefUpoFixture::ksefNumber($issuance->seller_nip);
        $fake->statusResponse = [
            'status' => ['code' => 200, 'description' => 'Accepted'], 'ksefNumber' => $number,
            'invoicingMode' => 'Offline', 'acquisitionDate' => $this->instant->addMinute()->toIso8601String(),
            'invoicingDate' => $this->instant->toIso8601String(), 'permanentStorageDate' => $this->instant->addMinutes(2)->toIso8601String(),
        ];
        $accepted = app(KsefInvoiceSubmissionService::class)->refreshStatus($submission);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $accepted->status);
        $this->assertSame($number, $accepted->ksef_number);
        $this->assertSame($rootNumber, $root->ksefSubmissions()->firstOrFail()->ksef_number);
        $blocks = app(KsefOfflinePresentationPdfRenderer::class)->acceptedOfflineInvoiceQrBlocks($presentation, $number);
        $this->assertCount(1, $blocks);
        $this->assertSame($number, $blocks[0]['label']);
        $requests = count(Http::recorded());
        $pdf = app(KsefAcceptedOfflineInvoicePdfService::class)->document($correction, $issuance, $accepted);
        $this->assertStringStartsWith('%PDF-', $pdf['contents']);
        $this->assertSame($requests, count(Http::recorded()));
        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$correction, $issuance]))->assertForbidden();
        $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$correction, $issuance]))->assertForbidden();
        $fake->upoResponse = KsefUpoFixture::xml([
            'session_reference' => $accepted->session_reference_number, 'ksef_number' => $number,
            'invoice_number' => $correction->number, 'invoice_hash' => $accepted->invoice_hash, 'mode' => 'Offline',
        ]);
        $fake->upoContentHash = base64_encode(hash('sha256', $fake->upoResponse, true));
        $upo = app(KsefInvoiceUpoService::class)->fetch($correction, $accepted);
        $this->assertTrue($accepted->upo()->firstOrFail()->is($upo));
    }

    #[DataProvider('deliveries')]
    public function test_procedure_aware_frozen_correction_presentation(string $procedure, bool $nip): void
    {
        [$root, $correction] = $this->scenario(nip: $nip);
        $this->evidence($procedure);
        $issuance = $this->issue($correction, $procedure);
        $expected = $nip && $procedure !== 'failure' ? KsefOfflineDeliveryDocumentType::TransactionConfirmation : KsefOfflineDeliveryDocumentType::OfflineInvoice;
        $this->assertSame($expected, app(KsefOfflineDeliveryPolicy::class)->primaryDocument($issuance));
        $data = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);
        $renderer = app(KsefOfflinePresentationPdfRenderer::class);
        $this->assertCount(2, $renderer->offlineInvoiceQrBlocks($data));
        $html = $renderer->offlineInvoiceHtml($data);
        foreach (['Faktura korygująca', $correction->number, $root->number, 'Przed:', 'Po:', 'Podsumowanie różnic Korekty', '100.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $route = $expected === KsefOfflineDeliveryDocumentType::TransactionConfirmation ? 'transaction-confirmation' : 'invoice-pdf';
        $this->get(route('invoices.ksef.offline-issuances.'.$route, [$correction, $issuance]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Numer Korekty:', $renderer->transactionConfirmationHtml($data));
        Http::assertNothingSent();
    }

    public static function deliveries(): array
    {
        return [['offline24', true], ['offline24', false], ['planned_unavailability', true], ['planned_unavailability', false], ['failure', true], ['failure', false]];
    }

    public function test_read_only_ui_and_double_click_no_online_or_blind_rejected_retry(): void
    {
        [$root, $correction] = $this->scenario();
        $url = route('invoices.corrections.edit', $correction);
        $this->get($url)->assertOk()->assertSee('data-ksef-offline24-form', false)->assertSee('Korekta zostanie zamknięta');
        $this->assertFalse($correction->fresh()->isFinalized());
        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        $this->post(route('invoices.ksef.offline24.issue', $correction))->assertRedirect()->assertSessionHasNoErrors();
        $issuance = KsefOfflineIssuance::query()->where('invoice_id', $correction->id)->firstOrFail();
        $this->assertError('ksef_offline24_already_issued', fn () => $this->issue($correction));
        $this->assertError('ksef_submission_blocked_by_offline_issuance', fn () => app(KsefInvoiceSubmissionService::class)->prepareCorrection($correction->fresh(), KsefEnvironment::Test));
        $this->get($url)->assertOk()->assertDontSee('data-ksef-offline24-form', false)->assertSee('Offline24')->assertSee('P_1:')->assertDontSee($issuance->invoice_hash);
        $this->get(route('invoices.corrections.index'))->assertOk()->assertSee('data-offline24-obligation', false);
        $submission = app(KsefOfflineInvoiceSubmissionService::class)->prepare($correction, $issuance);
        $submission->forceFill(['status' => KsefInvoiceSubmissionStatus::Rejected])->save();
        $this->assertError('ksef_offline_submission_rejected_retry_blocked', fn () => app(KsefOfflineInvoiceSubmissionService::class)->prepare($correction, $issuance));
        $obligation = app(KsefOfflineSubmissionObligationQueryService::class)->forInvoices([$correction], $this->instant)->get($correction->id)->first()['obligation'];
        $this->assertSame(KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired, $obligation->status);
        $this->assertDatabaseCount('ksef_offline_issuances', 1);
        Http::assertNothingSent();
    }

    public function test_source_contradiction_blocks_frozen_submission_without_rewriting_payload(): void
    {
        [$root, $correction] = $this->scenario();
        $issuance = $this->issue($correction);
        $xml = $issuance->payload_xml;
        $root->ksefSubmissions()->update(['ksef_number' => $this->validKsefNumber('9876543210', '000000000099')]);
        $this->assertError('ksef_offline_submission_integrity_invalid', fn () => app(KsefOfflineInvoiceSubmissionService::class)->prepare($correction, $issuance));
        $this->assertSame($xml, $issuance->fresh()->payload_xml);
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $correction->id]);
        Http::assertNothingSent();
    }

    public function test_warsaw_midnight_uses_own_p1_and_exact_utc_and_wrong_day_rolls_back(): void
    {
        $this->instant = $this->instant->startOfDay()->addHours(23)->addMinutes(30);
        $this->travelTo($this->instant);
        [, $correction] = $this->scenario();
        $correction->forceFill(['issue_date' => $this->instant->toDateString()])->saveQuietly();
        $this->assertError('ksef_offline24_issue_date_not_today', fn () => $this->issue($correction));
        $this->assertFalse($correction->fresh()->isFinalized());
        $issuance = $this->issue($this->currentDate($correction));
        $this->assertNotSame($issuance->issued_at->toDateString(), $issuance->issue_date->toDateString());
        $this->assertSame($this->instant->getTimestamp(), $issuance->fresh()->issued_at->getTimestamp());
        $this->assertSame($issuance->issue_date->toDateString(), $this->ksefValue($this->ksefXpath($issuance->payload_xml), '/fa:Faktura/fa:Fa/fa:P_1'));
        Http::assertNothingSent();
    }

    #[DataProvider('integrityCases')]
    public function test_frozen_correction_integrity_rejects_corruption_without_transmission(string $case): void
    {
        [, $correction] = $this->scenario();
        $issuance = $this->issue($correction);
        $managed = $correction->fresh();
        match ($case) {
            'hash' => $issuance->forceFill(['invoice_hash' => base64_encode(str_repeat('x', 32))]),
            'size' => $issuance->forceFill(['invoice_size' => $issuance->invoice_size + 1]),
            'schema' => $issuance->forceFill(['schema_id' => 'FA (2)']),
            'p1' => $issuance->forceFill(['issue_date' => $issuance->issue_date->subDay()]),
            'seller' => $issuance->forceFill(['seller_nip' => '5260250995']),
            'p2' => $managed->forceFill(['number' => 'FAKE DIFFERENT NUMBER']),
            'relationship' => $managed->forceFill(['corrected_invoice_id' => null]),
            'type' => $managed->forceFill(['document_type' => 'proforma']),
            'ciphertext' => $issuance->setRawAttributes(array_merge($issuance->getAttributes(), ['payload_xml' => 'FAKE_UNDECRYPTABLE_PAYLOAD'])),
        };
        $this->assertError('ksef_offline_submission_integrity_invalid', fn () => app(KsefOfflineSubmissionIntegrityService::class)->assertIssuance($issuance, $managed));
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $correction->id]);
        Http::assertNothingSent();
    }

    public static function integrityCases(): array
    {
        return array_map(fn (string $case): array => [$case], ['hash', 'size', 'schema', 'p1', 'seller', 'p2', 'relationship', 'type', 'ciphertext']);
    }

    #[DataProvider('blockedProcedures')]
    public function test_procedure_guards_and_environment_are_shared_and_atomic(string $case, string $procedure, string $error): void
    {
        $environment = match ($case) {
            'demo' => KsefEnvironment::Demo,
            'production' => KsefEnvironment::Production,
            default => KsefEnvironment::Test,
        };
        [, $correction] = $this->scenario(environment: $environment);
        $this->evidence($procedure);
        if ($case === 'stale') {
            KsefLatarniaSyncState::query()->firstOrFail()->forceFill(['messages_last_success_at' => $this->instant->subHour()])->save();
        } elseif (in_array($case, ['status', 'total'], true)) {
            KsefLatarniaSyncState::query()->firstOrFail()->forceFill(['current_status' => $case === 'total' ? 'TOTAL_FAILURE' : 'AVAILABLE'])->save();
        } elseif ($case === 'ambiguous') {
            $copy = KsefLatarniaMessage::query()->firstOrFail()->replicate();
            $copy->forceFill(['event_id' => 807, 'external_message_id' => 'FAKE-AMBIGUOUS'])->save();
        }
        $this->assertError($error, fn () => $this->issue($correction, $procedure));
        $this->assertFalse($correction->fresh()->isFinalized());
        $this->assertDatabaseMissing('ksef_offline_issuances', ['invoice_id' => $correction->id]);
        Http::assertNothingSent();
    }

    public static function blockedProcedures(): array
    {
        return [
            ['demo', 'planned_unavailability', 'ksef_offline_procedure_unsupported_environment'],
            ['demo', 'failure', 'ksef_offline_procedure_unsupported_environment'],
            ['production', 'offline24', 'ksef_operational_environment_blocked'],
            ['stale', 'planned_unavailability', 'ksef_offline_procedure_latarnia_stale'],
            ['stale', 'failure', 'ksef_offline_procedure_latarnia_stale'],
            ['status', 'failure', 'ksef_offline_procedure_status_mismatch'],
            ['total', 'failure', 'ksef_offline_procedure_status_mismatch'],
            ['ambiguous', 'planned_unavailability', 'ksef_offline_procedure_latarnia_ambiguous'],
        ];
    }

    public function test_demo_offline24_and_source_block_reason_do_not_use_http_or_finalize_on_get(): void
    {
        [$root, $correction] = $this->scenario(environment: KsefEnvironment::Demo);
        $submission = $root->ksefSubmissions()->firstOrFail();
        $submission->forceFill(['status' => 'processing', 'ksef_number' => null])->save();
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertDontSee('data-ksef-offline24-form', false)
            ->assertSee('Faktura pierwotna nie została zaakceptowana');
        $this->assertFalse($correction->fresh()->isFinalized());
        $submission->forceFill(['status' => 'accepted', 'ksef_number' => $this->validKsefNumber('9876543210', '000000000001')])->save();
        $issuance = $this->issue($correction);
        $this->assertSame(KsefEnvironment::Demo, $issuance->environment);
        $this->assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/', $issuance->invoice_verification_url);
        $this->assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/', $issuance->certificate_verification_url);
        Http::assertNothingSent();
    }

    #[DataProvider('presentationCases')]
    public function test_buyer_only_and_negative_corrections_preserve_frozen_semantics(string $case): void
    {
        [$root, $current] = $this->scenario();
        app(InvoiceDeletionService::class)->delete($current, $current->lock_version, $this->documentContext());
        if ($case === 'buyer') {
            $buyer = $root->buyer_snapshot;
            $buyer['company_name'] = 'FAKE New buyer name';
            $correction = $this->currentDate($this->issueKsefBuyerCorrection($root, $buyer));
        } else {
            $correction = $this->currentDate($this->issueKsefFinancialCorrection($root, 0));
        }
        $issuance = $this->issue($correction);
        $data = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);
        if ($case === 'buyer') {
            $this->assertSame([], $data->lines);
            $this->assertSame('0.00', $data->totalGross);
            $this->assertNotNull($data->correction['buyer_before']);
            $this->assertSame('FAKE New buyer name', $data->buyer['name']);
        } else {
            $this->assertSame('-123.00', $data->totalGross);
            $this->assertSame('-100.00', $data->correction['pairs'][1]['delta_net']);
        }
        $html = app(KsefOfflinePresentationPdfRenderer::class)->offlineInvoiceHtml($data);
        $correction->forceFill(['buyer_snapshot' => [], 'total_gross' => '9999.00', 'correction_reason' => 'MUTATED'])->saveQuietly();
        $this->assertSame($html, app(KsefOfflinePresentationPdfRenderer::class)->offlineInvoiceHtml(app(KsefOfflinePresentationDataExtractor::class)->extract($issuance->fresh())));
        $this->assertStringStartsWith('%PDF-', app(KsefOfflinePresentationPdfRenderer::class)->renderOfflineInvoice($data));
        Http::assertNothingSent();
    }

    public static function presentationCases(): array
    {
        return [['buyer'], ['negative']];
    }

    public function test_finalized_correction_cannot_be_mutated_deleted_or_reissued_after_online_history(): void
    {
        [, $correction] = $this->scenario();
        $submission = $this->acceptKsefDocument($correction, KsefEnvironment::Test);
        $submission->forceFill(['status' => 'rejected', 'ksef_number' => null])->save();
        $this->assertError('ksef_offline24_submission_history_exists', fn () => $this->issue($correction));
        $this->assertFalse($correction->fresh()->isFinalized());
        $submission->delete();
        $issuance = $this->issue($correction);
        $managed = $correction->fresh();
        $this->assertError('correction_finalized', fn () => app(InvoiceMutationPolicy::class)->assertContentMutable($managed));
        $this->assertError('correction_finalized', fn () => app(InvoiceDeletionService::class)->delete($managed, $managed->lock_version, $this->documentContext()));
        $this->assertDatabaseHas('ksef_offline_issuances', ['id' => $issuance->id, 'invoice_id' => $correction->id]);
        Http::assertNothingSent();
    }

    #[DataProvider('procedures')]
    public function test_deadline_parity_total_failure_projection_and_fixed_mixed_query_count(string $procedure): void
    {
        [$root, $correction] = $this->scenario();
        $this->evidence($procedure);
        $issuance = $this->issue($correction, $procedure);
        $invoiceIssuance = $issuance->replicate();
        $invoiceIssuance->forceFill(['invoice_id' => $root->id])->save();
        $messages = KsefLatarniaMessage::all();
        $engine = app(KsefOfflineSubmissionObligationEngine::class);
        $asOf = $this->instant->addHours(2);
        if ($procedure === 'failure') {
            $end = $messages->first()->replicate();
            $end->forceFill([
                'external_message_id' => 'FAKE-FAILURE-END', 'type' => 'FAILURE_END',
                'end_at' => $this->instant->addHour(), 'published_at' => $this->instant->addHour(),
                'first_fetched_at' => $this->instant->addHour(),
            ]);
            $messages->push($end);
        }
        $result = $engine->evaluate($issuance, $messages, [], $asOf, KsefLatarniaEvidenceCoverage::Complete);
        $primary = $engine->evaluate($invoiceIssuance, $messages, [], $asOf, KsefLatarniaEvidenceCoverage::Complete);
        $this->assertEquals($primary, $result);
        $calendar = app(PolishBusinessDayCalendar::class);
        $expected = $procedure === 'failure'
            ? $calendar->addBusinessDaysAfter($this->instant->addHour()->setTimezone('Europe/Warsaw'), 7)
            : $calendar->nextBusinessDayAfter(($procedure === 'offline24' ? $issuance->issue_date : $this->instant->addHour()->setTimezone('Europe/Warsaw')));
        $this->assertSame($expected->toDateString(), $result->effectiveDeadline?->toDateString());
        $total = new KsefLatarniaMessage([
            'source_environment' => 'test', 'external_message_id' => 'FAKE-TOTAL', 'event_id' => 900,
            'version' => 1, 'category' => 'TOTAL_FAILURE', 'type' => 'FAILURE_START',
            'start_at' => $this->instant->addMinute(), 'published_at' => $this->instant->addMinute(),
            'first_fetched_at' => $this->instant->addMinute(),
        ]);
        $totalResult = $engine->evaluate($issuance, [...$messages, $total], [], $asOf, KsefLatarniaEvidenceCoverage::Complete);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure, $totalResult->status);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $rows = app(KsefOfflineSubmissionObligationQueryService::class)->forInvoices([$root, $correction], $asOf);
        $this->assertCount(2, $rows);
        $this->assertCount(4, $queries);
        $this->assertSame($procedure, $issuance->fresh()->procedure->value);
        Http::assertNothingSent();
    }

    private function scenario(string $source = 'online', bool $nip = true, KsefEnvironment $environment = KsefEnvironment::Test): array
    {
        $settings = $this->ksefSettings($environment);
        $settings->forceFill(['is_active' => true, 'context_nip' => '9876543210', 'send_without_buyer_nip' => ! $nip])->save();
        $certificate = app(KsefOfflineCertificateService::class)->import($environment, 'FAKE Offline correction certificate', $this->certificateFixture['certificate'], $this->certificateFixture['private_key'], null);
        $certificate->forceFill([
            'remote_status' => 'Active', 'remote_valid_from' => $this->instant->subDay(),
            'remote_valid_until' => $this->instant->addDay(), 'remote_verified_at' => $this->instant->subMinute(),
        ])->save();
        app(KsefOfflineCertificateService::class)->setPreferred($certificate, $environment);
        $root = $this->issueKsefRoot(orderAttributes: ['billing_tax_id' => $nip ? '5260250995' : null]);
        if (str_starts_with($source, 'offline')) {
            $root = app(InvoiceFinalizationService::class)->finalize($this->currentDate($root));
            KsefSeriesSetting::query()->updateOrCreate(['invoice_series_id' => $root->invoice_series_id], ['is_enabled' => true]);
            $offline = app(KsefOfflineIssuanceService::class)->issueOffline24($root);
        }
        if ($source === 'outside') {
            $this->markKsefOutside($root, $environment);
        } elseif ($source !== 'offline') {
            $accepted = $this->acceptKsefDocument($root, $source === 'wrong_environment' ? KsefEnvironment::Demo : $environment);
            if ($source === 'offline_accepted') {
                $accepted->forceFill(['offline_issuance_id' => $offline->id, 'invoicing_mode' => KsefInvoicingMode::Offline])->save();
            }
            if ($source === 'ambiguous') {
                $this->acceptKsefDocument($root, $environment);
            }
            if (in_array($source, ['rejected', 'processing', 'uncertain'], true)) {
                $accepted->forceFill(['status' => $source, 'ksef_number' => null])->save();
            }
        }
        $correction = $this->currentDate($this->issueKsefFinancialCorrection($root));
        KsefSeriesSetting::query()->updateOrCreate(['invoice_series_id' => $correction->invoice_series_id], ['is_enabled' => true]);

        return [$root, $correction];
    }

    private function currentDate(Invoice $document): Invoice
    {
        $document->forceFill(['issue_date' => $this->instant->setTimezone('Europe/Warsaw')->toDateString()])->saveQuietly();

        return $document->fresh('items');
    }

    private function issue(Invoice $correction, string $procedure = 'offline24'): KsefOfflineIssuance
    {
        $method = match ($procedure) {
            'offline24' => 'issueCorrectionOffline24',
            'planned_unavailability' => 'issueCorrectionPlannedUnavailability',
            'failure' => 'issueCorrectionFailure',
        };

        return app(KsefOfflineIssuanceService::class)->{$method}($correction);
    }

    private function evidence(string $procedure): void
    {
        if ($procedure === 'offline24') {
            return;
        }
        $maintenance = $procedure === 'planned_unavailability';
        KsefLatarniaSyncState::query()->create([
            'source_environment' => 'test', 'current_status' => $maintenance ? 'MAINTENANCE' : 'FAILURE',
            'status_payload_json' => '{}', 'status_payload_hash' => hash('sha256', '{}'),
            'status_last_success_at' => $this->instant->subMinute(), 'messages_last_success_at' => $this->instant->subMinute(),
            'messages_coverage_from_at' => $this->instant->subDay(), 'messages_coverage_through_at' => $this->instant->subMinute(),
        ]);
        KsefLatarniaMessage::query()->create([
            'source_environment' => 'test', 'external_message_id' => 'FAKE-8C6', 'event_id' => 806,
            'version' => 1, 'category' => $maintenance ? 'MAINTENANCE' : 'FAILURE',
            'type' => $maintenance ? 'MAINTENANCE_ANNOUNCEMENT' : 'FAILURE_START',
            'title' => 'FAKE Offline correction evidence', 'text' => 'Synthetic fixture only',
            'start_at' => $this->instant->subHour(), 'end_at' => $maintenance ? $this->instant->addHour() : null,
            'published_at' => $this->instant->subMinutes(30), 'first_fetched_at' => $this->instant->subMinutes(10),
            'last_seen_at' => $this->instant->subMinute(), 'payload_json' => '{}', 'payload_hash' => hash('sha256', '{}'),
        ]);
    }

    private function assertError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected controlled error: '.$code);
        } catch (KsefApiException|InvoiceDomainException $exception) {
            $this->assertSame($code, $exception instanceof KsefApiException ? $exception->safeCode : $exception->errorCode());
        }
    }
}
