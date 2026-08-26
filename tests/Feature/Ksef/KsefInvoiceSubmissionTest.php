<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefPaymentMethodMapping;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefInvoiceSubmissionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_migration_model_and_prepare_freeze_exact_encrypted_authoritative_payload(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = app(KsefInvoiceSubmissionService::class)->prepare($invoice);
        $xml = $submission->payload_xml;
        $rawPayload = DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->value('payload_xml');

        $this->assertTrue(Schema::hasColumns('ksef_invoice_submissions', [
            'invoice_id',
            'environment',
            'context_nip',
            'seller_nip',
            'attempt_number',
            'status',
            'payload_xml',
            'invoice_hash',
            'invoice_size',
            'invoice_reference_number',
            'ksef_number',
        ]));
        $this->assertFalse(Schema::hasColumn('ksef_invoice_submissions', 'cipher_key'));
        $this->assertFalse(Schema::hasColumn('ksef_invoice_submissions', 'cipher_iv'));
        $this->assertFalse(Schema::hasColumn('ksef_invoice_submissions', 'encrypted_invoice_content'));
        $this->assertSame(KsefEnvironment::Test, $submission->environment);
        $this->assertSame('9876543210', $submission->context_nip);
        $this->assertSame('9876543210', $submission->seller_nip);
        $this->assertSame(KsefInvoiceSubmissionStatus::Preparing, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame('FA (3) 1-0E', $submission->schema_id);
        $this->assertStringContainsString('<Faktura', $xml);
        $this->assertSame(base64_encode(hash('sha256', $xml, true)), $submission->invoice_hash);
        $this->assertSame(strlen($xml), $submission->invoice_size);
        $this->assertNotSame($xml, $rawPayload);
        $this->assertStringNotContainsString('<Faktura', $rawPayload);
        $this->assertStringNotContainsString('9876543210', $rawPayload);
        $this->assertArrayNotHasKey('payload_xml', $submission->toArray());
        $this->assertTrue($invoice->ksefSubmissions()->firstOrFail()->is($submission));
        $this->assertTrue($submission->invoice->is($invoice));

        $generatedAt = $submission->generated_at->toISOString();
        $invoice->order->forceFill(['notes' => 'CHANGED AFTER PREPARE'])->save();
        $invoice->series->forceFill(['name' => 'Changed after prepare'])->save();
        app(KsefSettingsService::class)->get()->forceFill([
            'include_gtu' => false,
            'context_nip' => '1234567890',
        ])->save();
        KsefPaymentMethodMapping::query()->create([
            'source_kind' => 'payment_method',
            'source_key' => 'changed-after-prepare',
            'source_label' => 'Changed after prepare',
            'target_type' => 'card',
        ]);

        $submission->refresh();
        $this->assertSame($xml, $submission->payload_xml);
        $this->assertSame($generatedAt, $submission->generated_at->toISOString());
        $this->assertSame(base64_encode(hash('sha256', $xml, true)), $submission->invoice_hash);
        $this->assertSame('9876543210', $submission->context_nip);
        $this->assertSame('9876543210', $submission->seller_nip);
        Http::assertNothingSent();
    }

    public function test_prepare_freezes_context_and_seller_as_separate_identities(): void
    {
        $invoice = $this->eligibleInvoice(
            contextNip: '5260250995',
            sellerNip: '9876543210',
        );

        $submission = app(KsefInvoiceSubmissionService::class)->prepare($invoice);

        $this->assertSame('5260250995', $submission->context_nip);
        $this->assertSame('9876543210', $submission->seller_nip);
        Http::assertNothingSent();
    }

    public function test_prepare_rejects_expected_context_drift_before_creating_submission(): void
    {
        $invoice = $this->eligibleInvoice(contextNip: '5260250995');

        $this->expectKsefError(
            'ksef_submission_context_changed',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare(
                $invoice,
                KsefEnvironment::Test,
                true,
                '9876543210',
            ),
        );

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_prepare_rejects_disabled_transport_production_and_unsupported_documents_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        config()->set('ksef.invoice_submission_enabled', false);
        $this->expectKsefError(
            'ksef_submission_disabled',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );

        config()->set('ksef.invoice_submission_enabled', true);
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Production,
        ])->save();
        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );

        app(KsefSettingsService::class)->get()->forceFill(['environment' => KsefEnvironment::Test])->save();
        foreach ([InvoiceDocumentType::Proforma, InvoiceDocumentType::Correction] as $type) {
            $invoice->forceFill(['document_type' => $type])->saveQuietly();
            $this->expectKsefError(
                'ksef_submission_document_type_invalid',
                fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice->fresh()),
            );
        }

        Http::assertNothingSent();
    }

    #[DataProvider('crossEnvironmentHistoryCases')]
    public function test_submission_history_is_isolated_per_environment(
        KsefEnvironment $historyEnvironment,
        KsefEnvironment $currentEnvironment,
        KsefInvoiceSubmissionStatus $historyStatus,
    ): void {
        $invoice = $this->eligibleInvoice(environment: $historyEnvironment);
        $service = app(KsefInvoiceSubmissionService::class);
        $history = $service->prepare($invoice);
        $history->forceFill(['status' => $historyStatus])->save();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => $currentEnvironment,
        ])->save();

        $current = $service->prepare($invoice);

        $this->assertSame($currentEnvironment, $current->environment);
        $this->assertSame(1, $current->attempt_number);
        $this->assertSame($historyStatus, $history->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        Http::assertNothingSent();
    }

    public static function crossEnvironmentHistoryCases(): array
    {
        return [
            'accepted TEST does not block DEMO' => [
                KsefEnvironment::Test,
                KsefEnvironment::Demo,
                KsefInvoiceSubmissionStatus::Accepted,
            ],
            'uncertain TEST does not block DEMO' => [
                KsefEnvironment::Test,
                KsefEnvironment::Demo,
                KsefInvoiceSubmissionStatus::Uncertain,
            ],
            'accepted DEMO does not block TEST' => [
                KsefEnvironment::Demo,
                KsefEnvironment::Test,
                KsefInvoiceSubmissionStatus::Accepted,
            ],
            'uncertain DEMO does not block TEST' => [
                KsefEnvironment::Demo,
                KsefEnvironment::Test,
                KsefInvoiceSubmissionStatus::Uncertain,
            ],
        ];
    }

    public function test_demo_attempt_number_starts_at_one_after_multiple_test_attempts(): void
    {
        $invoice = $this->eligibleInvoice();
        $service = app(KsefInvoiceSubmissionService::class);
        $first = $service->prepare($invoice);
        $first->forceFill(['status' => KsefInvoiceSubmissionStatus::TechnicalFailed])->save();
        $second = $service->prepare($invoice);
        $second->forceFill(['status' => KsefInvoiceSubmissionStatus::Accepted])->save();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
        ])->save();

        $demo = $service->prepare($invoice);

        $this->assertSame(KsefEnvironment::Demo, $demo->environment);
        $this->assertSame(1, $demo->attempt_number);
        $this->assertSame(2, $second->attempt_number);
        Http::assertNothingSent();
    }

    #[DataProvider('demoBlockingStatuses')]
    public function test_demo_history_blocks_another_demo_attempt(
        KsefInvoiceSubmissionStatus $status,
        string $safeCode,
    ): void {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $submission->forceFill(['status' => $status])->save();

        $this->expectKsefError($safeCode, fn () => $service->prepare($invoice));

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function demoBlockingStatuses(): array
    {
        return [
            'accepted' => [
                KsefInvoiceSubmissionStatus::Accepted,
                'ksef_submission_already_exists',
            ],
            'uncertain' => [
                KsefInvoiceSubmissionStatus::Uncertain,
                'ksef_submission_reconciliation_required',
            ],
        ];
    }

    public function test_prepare_requires_finalized_invoice_active_ksef_and_enabled_series(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->expectInvoiceError(
            'ksef_fa3_document_not_finalized',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );
        $this->assertNull($invoice->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);

        $invoice = app(InvoiceFinalizationService::class)->finalize($invoice);
        app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        $this->expectKsefError(
            'ksef_submission_configuration_inactive',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );

        app(KsefSettingsService::class)->get()->forceFill(['is_active' => true])->save();
        KsefSeriesSetting::query()->where('invoice_series_id', $invoice->invoice_series_id)->update(['is_enabled' => false]);
        $this->expectKsefError(
            'ksef_submission_series_disabled',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );
        Http::assertNothingSent();
    }

    public function test_prepare_requires_valid_frozen_context_without_creating_submission(): void
    {
        $invoice = $this->eligibleInvoice();

        foreach ([null, '', '123456789'] as $contextNip) {
            app(KsefSettingsService::class)->get()->forceFill(['context_nip' => $contextNip])->save();
            $this->expectKsefError(
                'ksef_submission_context_missing',
                fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
            );
        }

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_fake_online_flow_uses_exact_contract_frozen_environment_and_never_sends_plain_xml(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $xml = $submission->payload_xml;
        app(KsefSettingsService::class)->get()->forceFill(['environment' => KsefEnvironment::Demo])->save();

        $submission = $service->submit($submission);

        $this->assertSame(KsefEnvironment::Test, $submission->environment);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('SYMMETRIC-KEY-ID', $submission->public_key_id);
        $this->assertSame('20260819-SO-TEST-REFERENCE', $submission->session_reference_number);
        $this->assertSame('20260819-INV-TEST-REFERENCE', $submission->invoice_reference_number);
        $this->assertNotNull($submission->session_closed_at);
        $this->assertNull($submission->ksef_number);
        $this->assertNull($submission->session_close_error_code);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame([
            'systemCode' => 'FA (3)',
            'schemaVersion' => '1-0E',
            'value' => 'FA',
        ], $fake->openPayload['formCode']);
        $this->assertSame('SYMMETRIC-KEY-ID', data_get($fake->openPayload, 'encryption.publicKeyId'));
        $this->assertSame($submission->invoice_hash, $fake->sendPayload['invoiceHash']);
        $this->assertSame($submission->invoice_size, $fake->sendPayload['invoiceSize']);
        $this->assertSame($submission->encrypted_invoice_hash, $fake->sendPayload['encryptedInvoiceHash']);
        $this->assertSame($submission->encrypted_invoice_size, $fake->sendPayload['encryptedInvoiceSize']);
        $ciphertext = base64_decode($fake->sendPayload['encryptedInvoiceContent'], true);
        $this->assertSame(base64_encode(hash('sha256', $ciphertext, true)), $fake->sendPayload['encryptedInvoiceHash']);
        $this->assertSame(strlen($ciphertext), $fake->sendPayload['encryptedInvoiceSize']);
        $this->assertStringNotContainsString('<Faktura', json_encode($fake->sendPayload, JSON_THROW_ON_ERROR));
        $this->assertSame($xml, $submission->payload_xml);

        Http::assertSent(function (Request $request): bool {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if (str_ends_with($path, '/security/public-key-certificates')) {
                return ! $request->hasHeader('Authorization');
            }

            return ! str_contains($path, '/sessions/')
                || $request->hasHeader('Authorization', 'Bearer FAKE_VALID_SUBMISSION_ACCESS_TOKEN');
        });
    }

    public function test_demo_online_flow_uses_only_demo_host_and_posts_invoice_once(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);

        $submission = $service->submit($service->prepare($invoice));

        $this->assertSame(KsefEnvironment::Demo, $submission->environment);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame(1, $fake->publicKeyCalls);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(0, $fake->statusCalls);
        Http::assertSentCount(4);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api-demo.ksef.mf.gov.pl', parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public function test_demo_status_refresh_uses_demo_host_and_maps_processing(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Submitted,
            'session_reference_number' => '20260819-SO-DEMO-REFERENCE',
            'invoice_reference_number' => '20260819-INV-DEMO-REFERENCE',
        ])->save();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];

        $submission = $service->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api-demo.ksef.mf.gov.pl');
    }

    #[DataProvider('demoReconciliationCases')]
    public function test_demo_reconciliation_uses_demo_lookup_without_invoice_resend(
        bool $knownInvoiceReference,
    ): void {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $invoiceReference = '20260819-INV-DEMO-RECONCILIATION';
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Uncertain,
            'session_reference_number' => '20260819-SO-DEMO-REFERENCE',
            'invoice_reference_number' => $knownInvoiceReference ? $invoiceReference : null,
        ])->save();
        $status = [
            'referenceNumber' => $invoiceReference,
            'invoiceHash' => $submission->invoice_hash,
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];
        $fake->statusResponse = $status;
        $fake->sessionInvoicesResponse = ['invoices' => [$status]];

        $submission = $service->reconcile($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame($invoiceReference, $submission->invoice_reference_number);
        $this->assertSame($knownInvoiceReference ? 1 : 0, $fake->statusCalls);
        $this->assertSame($knownInvoiceReference ? 0 : 1, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->sendCalls);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api-demo.ksef.mf.gov.pl');
    }

    public static function demoReconciliationCases(): array
    {
        return [
            'known invoice reference' => [true],
            'missing invoice reference' => [false],
        ];
    }

    public function test_production_prepare_submit_refresh_and_reconcile_are_blocked_before_http(): void
    {
        $service = app(KsefInvoiceSubmissionService::class);
        $productionInvoice = $this->eligibleInvoice(environment: KsefEnvironment::Production);
        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => $service->prepare($productionInvoice),
        );
        Http::assertNothingSent();

        $invoice = $this->eligibleInvoice();
        $submission = $service->prepare($invoice);
        $submission->forceFill(['environment' => KsefEnvironment::Production])->save();

        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => $service->submit($submission),
        );
        Http::assertNothingSent();

        $submission->forceFill(['status' => KsefInvoiceSubmissionStatus::Submitted])->save();
        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => $service->refreshStatus($submission),
        );
        Http::assertNothingSent();

        $submission->forceFill(['status' => KsefInvoiceSubmissionStatus::Uncertain])->save();
        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => $service->reconcile($submission),
        );
        Http::assertNothingSent();
    }

    public function test_status_refresh_maps_processing_then_accepted_without_regenerating_payload(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));
        $xml = $submission->payload_xml;

        $submission = $service->refreshStatus($submission);
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertNull($submission->ksef_number);

        $invoice->forceFill(['seller_snapshot' => []])->saveQuietly();
        Event::fake([KsefInvoiceAccepted::class]);
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-19T10:00:00Z',
            'acquisitionDate' => '2026-08-19T10:00:01Z',
            'permanentStorageDate' => '2026-08-19T10:00:02Z',
            'ordinalNumber' => 1,
            'ksefNumber' => $this->validKsefNumber($submission->seller_nip),
            'status' => ['code' => 200, 'description' => 'Sukces'],
        ];
        $submission = $service->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame($this->validKsefNumber('9876543210'), $submission->ksef_number);
        $this->assertSame('2026-08-19T10:00:01.000000Z', $submission->acquisition_date->toISOString());
        $this->assertSame($xml, $submission->payload_xml);
        $this->assertSame(2, $fake->statusCalls);
        Event::assertDispatched(KsefInvoiceAccepted::class, function (KsefInvoiceAccepted $event) use ($invoice, $submission): bool {
            $payload = $event->payload();

            return $event->submission->is($submission)
                && $payload['order_id'] === $invoice->order_id
                && $payload['invoice_id'] === $invoice->getKey()
                && $payload['invoice_number'] === $invoice->number
                && $payload['submission_id'] === $submission->getKey()
                && $payload['environment'] === KsefEnvironment::Test->value
                && $payload['ksef_number'] === $submission->ksef_number
                && ! array_key_exists('payload_xml', $payload);
        });
        Event::assertDispatchedTimes(KsefInvoiceAccepted::class, 1);
    }

    #[DataProvider('conservativeStatusCases')]
    public function test_status_mapping_is_conservative_for_rejection_success_without_number_and_unknown(
        int $code,
        ?string $ksefNumber,
        KsefInvoiceSubmissionStatus $expected,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-19T10:00:00Z',
            'ordinalNumber' => 1,
            'ksefNumber' => $ksefNumber,
            'status' => ['code' => $code, 'description' => 'Synthetic status'],
            'details' => ['NIP 9876543210 must not be persisted'],
        ];

        $submission = $service->refreshStatus($submission);

        $this->assertSame($expected, $submission->status);
        $this->assertStringNotContainsString('9876543210', (string) $submission->safe_error_message);
        if ($expected === KsefInvoiceSubmissionStatus::Rejected) {
            $this->assertSame(415, $submission->ksef_status_code);
            $this->assertSame('ksef_invoice_rejected', $submission->safe_error_code);
            $this->assertSame(
                'KSeF odrzucił Fakturę podczas weryfikacji.',
                $submission->safe_error_message,
            );
            $this->assertStringNotContainsString('SECRET TEST DETAIL', $submission->safe_error_message);
        }
        if ($expected !== KsefInvoiceSubmissionStatus::Accepted) {
            $this->assertNull($submission->ksef_number);
        }
    }

    public static function conservativeStatusCases(): array
    {
        return [
            'known terminal rejection' => [415, null, KsefInvoiceSubmissionStatus::Rejected],
            'success without KSeF number' => [200, null, KsefInvoiceSubmissionStatus::Uncertain],
            'unknown status' => [299, null, KsefInvoiceSubmissionStatus::Uncertain],
        ];
    }

    #[DataProvider('statusIdentityFailureCases')]
    public function test_status_identity_failure_is_uncertain_before_status_mapping(
        bool $includeReference,
        bool $includeHash,
        bool $wrongReference,
        bool $wrongHash,
        int $statusCode,
        string $expectedError,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));
        $response = [
            'invoicingDate' => '2026-08-19T10:00:00Z',
            'ordinalNumber' => 1,
            'ksefNumber' => $this->validKsefNumber($submission->seller_nip),
            'status' => ['code' => $statusCode, 'description' => 'Synthetic status'],
        ];
        if ($includeReference) {
            $response['referenceNumber'] = $wrongReference
                ? 'WRONG-INVOICE-REFERENCE'
                : $submission->invoice_reference_number;
        } else {
            $response['referenceNumber'] = null;
        }
        if ($includeHash) {
            $response['invoiceHash'] = $wrongHash
                ? 'WRONG-INVOICE-HASH'
                : $submission->invoice_hash;
        } else {
            $response['invoiceHash'] = null;
        }
        $fake->statusResponse = $response;

        $submission = $service->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame($expectedError, $submission->safe_error_code);
        $this->assertNull($submission->ksef_number);
        $this->assertStringNotContainsString('WRONG-', (string) $submission->safe_error_message);
    }

    public static function statusIdentityFailureCases(): array
    {
        return [
            'wrong reference on success' => [true, true, true, false, 200, 'ksef_invoice_status_identity_mismatch'],
            'wrong hash on success' => [true, true, false, true, 200, 'ksef_invoice_status_identity_mismatch'],
            'missing reference' => [false, true, false, false, 200, 'ksef_invoice_status_identity_missing'],
            'missing hash' => [true, false, false, false, 200, 'ksef_invoice_status_identity_missing'],
            'wrong identity on rejection' => [true, true, true, true, 415, 'ksef_invoice_status_identity_mismatch'],
            'wrong hash while processing' => [true, true, false, true, 150, 'ksef_invoice_status_identity_mismatch'],
        ];
    }

    public function test_structurally_valid_ksef_number_for_another_seller_is_uncertain(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-19T10:00:00Z',
            'ordinalNumber' => 1,
            'ksefNumber' => $this->validKsefNumber('5265877635'),
            'status' => ['code' => 200, 'description' => 'Sukces'],
        ];

        $submission = $service->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame('ksef_invoice_status_seller_mismatch', $submission->safe_error_code);
        $this->assertNull($submission->ksef_number);
        $this->assertStringNotContainsString('5265877635', (string) $submission->safe_error_message);
    }

    public function test_submit_blocks_context_drift_before_cached_token_or_session_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '1234567890'])->save();

        $this->expectKsefError(
            'ksef_submission_context_changed',
            fn () => $service->submit($submission),
        );

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $submission->status);
        $this->assertSame('9876543210', $submission->context_nip);
        $this->assertSame(0, $fake->publicKeyCalls);
        $this->assertSame(0, $fake->openCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_status_refresh_blocks_context_drift_without_changing_transport_state(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '1234567890'])->save();

        $this->expectKsefError(
            'ksef_submission_context_changed',
            fn () => $service->refreshStatus($submission),
        );

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('ksef_submission_context_changed', $submission->safe_error_code);
        $this->assertSame(0, $fake->statusCalls);
    }

    public function test_legacy_submission_without_context_never_uses_live_settings_as_fallback(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $preparing = $service->prepare($invoice);
        $preparing->forceFill(['context_nip' => null])->save();

        $this->expectKsefError(
            'ksef_submission_context_missing',
            fn () => $service->submit($preparing),
        );
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $preparing->fresh()->status);
        $this->assertSame(0, $fake->publicKeyCalls);

        $secondInvoice = $this->eligibleInvoice();
        $submitted = $service->submit($service->prepare($secondInvoice));
        $submitted->forceFill(['context_nip' => null])->save();
        $this->expectKsefError(
            'ksef_submission_context_missing',
            fn () => $service->refreshStatus($submitted),
        );
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submitted->fresh()->status);
        $this->assertSame(0, $fake->statusCalls);
    }

    #[DataProvider('uncertainSendFailures')]
    public function test_invoice_post_ambiguous_failures_become_uncertain_without_retry(array $failure, ?array $response): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $path = '/sessions/online/20260819-SO-TEST-REFERENCE/invoices';
        if ($failure !== []) {
            $fake->failures[$path] = $failure;
        }
        if ($response !== null) {
            $fake->sendResponse = $response;
        }
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);

        try {
            $service->submit($submission);
            $this->fail('Expected uncertain send failure.');
        } catch (\Throwable) {
            $submission->refresh();
            $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
            $this->assertSame(1, $fake->sendCalls);
            $this->assertSame(0, $fake->closeCalls);
            $this->expectKsefError(
                'ksef_submission_reconciliation_required',
                fn () => $service->prepare($invoice),
            );
        }
    }

    public static function uncertainSendFailures(): array
    {
        return [
            'connection error' => [['connection' => true], null],
            'server error' => [['status' => 500], null],
            'malformed success' => [[], []],
        ];
    }

    public function test_pre_send_and_definite_send_failures_are_technical_and_allow_next_attempt_number(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/security/public-key-certificates'] = ['status' => 500];
        $service = app(KsefInvoiceSubmissionService::class);
        $first = $service->prepare($invoice);
        $this->expectKsefError('http_500', fn () => $service->submit($first));
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $first->fresh()->status);
        $this->assertSame(0, $fake->sendCalls);

        unset($fake->failures['/security/public-key-certificates']);
        $second = $service->prepare($invoice);
        $this->assertSame(2, $second->attempt_number);
        $fake->failures['/sessions/online/20260819-SO-TEST-REFERENCE/invoices'] = ['status' => 400];
        $this->expectKsefError('http_400', fn () => $service->submit($second));
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $second->fresh()->status);
        $this->assertSame(1, $fake->sendCalls);
    }

    public function test_close_and_status_failures_preserve_submitted_state_and_invoice_reference(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/close'] = ['status' => 500];
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->submit($service->prepare($invoice));

        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('20260819-INV-TEST-REFERENCE', $submission->invoice_reference_number);
        $this->assertSame('SYNTHETIC_FAILURE', $submission->session_close_error_code);
        $this->assertNull($submission->session_closed_at);

        unset($fake->failures['/close']);
        $fake->failures['/sessions/20260819-SO-TEST-REFERENCE/invoices/20260819-INV-TEST-REFERENCE'] = [
            'connection' => true,
        ];
        $this->expectKsefError('network_error', fn () => $service->refreshStatus($submission));
        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('20260819-INV-TEST-REFERENCE', $submission->invoice_reference_number);
        $this->assertSame('network_error', $submission->safe_error_code);
    }

    public function test_reconciliation_recovers_missing_reference_by_exact_hash_and_accepts_without_resend(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $path = '/sessions/online/20260819-SO-TEST-REFERENCE/invoices';
        $fake->failures[$path] = ['connection' => true];
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $payload = $submission->payload_xml;

        $this->expectKsefError('network_error', fn () => $service->submit($submission));
        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertNull($submission->invoice_reference_number);
        unset($fake->failures[$path]);
        $fake->sessionInvoicesResponse = [
            'invoices' => [[
                'referenceNumber' => '20260819-INV-RECOVERED-REFERENCE',
                'invoiceHash' => $submission->invoice_hash,
                'invoicingDate' => '2026-08-19T10:00:00Z',
                'acquisitionDate' => '2026-08-19T10:00:01Z',
                'permanentStorageDate' => '2026-08-19T10:00:02Z',
                'ordinalNumber' => 1,
                'ksefNumber' => $this->validKsefNumber($submission->seller_nip),
                'status' => ['code' => 200, 'description' => 'Sukces'],
            ]],
        ];

        $submission = $service->reconcile($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('20260819-INV-RECOVERED-REFERENCE', $submission->invoice_reference_number);
        $this->assertSame($this->validKsefNumber('9876543210'), $submission->ksef_number);
        $this->assertSame($payload, $submission->payload_xml);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->statusCalls);
    }

    #[DataProvider('reconciliationStatusCases')]
    public function test_reconciliation_maps_safe_statuses_without_creating_attempt_or_invoice_post(
        int $code,
        KsefInvoiceSubmissionStatus $expected,
        ?string $expectedError,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Uncertain,
            'session_reference_number' => '20260819-SO-TEST-REFERENCE',
            'invoice_reference_number' => null,
        ])->save();
        $fake->sessionInvoicesResponse = [
            'invoices' => [[
                'referenceNumber' => '20260819-INV-RECONCILED-REFERENCE',
                'invoiceHash' => $submission->invoice_hash,
                'invoicingDate' => '2026-08-19T10:00:00Z',
                'ordinalNumber' => 1,
                'status' => ['code' => $code, 'description' => 'Synthetic reconciliation status'],
            ]],
        ];

        $submission = $service->reconcile($submission);

        $this->assertSame($expected, $submission->status);
        $this->assertSame($expectedError, $submission->safe_error_code);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(0, $fake->sendCalls);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
    }

    public static function reconciliationStatusCases(): array
    {
        return [
            'processing' => [150, KsefInvoiceSubmissionStatus::Processing, null],
            'rejected' => [415, KsefInvoiceSubmissionStatus::Rejected, 'ksef_invoice_rejected'],
            'unknown remains uncertain' => [299, KsefInvoiceSubmissionStatus::Uncertain, 'ksef_invoice_status_unknown'],
        ];
    }

    #[DataProvider('reconciliationIdentityCases')]
    public function test_reconciliation_preserves_all_existing_identity_guards(
        string $reference,
        string $hash,
        ?string $ksefNumber,
        string $expectedError,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Uncertain,
            'session_reference_number' => '20260819-SO-TEST-REFERENCE',
            'invoice_reference_number' => '20260819-INV-TEST-REFERENCE',
        ])->save();
        $fake->statusResponse = [
            'referenceNumber' => $reference === 'MATCH' ? $submission->invoice_reference_number : $reference,
            'invoiceHash' => $hash === 'MATCH' ? $submission->invoice_hash : $hash,
            'invoicingDate' => '2026-08-19T10:00:00Z',
            'ordinalNumber' => 1,
            'ksefNumber' => $ksefNumber === 'FOREIGN_VALID'
                ? $this->validKsefNumber('5265877635')
                : $ksefNumber,
            'status' => ['code' => 200, 'description' => 'Sukces'],
        ];

        $submission = $service->reconcile($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame($expectedError, $submission->safe_error_code);
        $this->assertNull($submission->ksef_number);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public static function reconciliationIdentityCases(): array
    {
        return [
            'reference mismatch' => ['WRONG-REFERENCE', 'MATCH', null, 'ksef_invoice_status_identity_mismatch'],
            'hash mismatch' => ['MATCH', 'WRONG-HASH', null, 'ksef_invoice_status_identity_mismatch'],
            'invalid KSeF number' => ['MATCH', 'MATCH', 'INVALID-KSEF-NUMBER', 'ksef_invoice_status_number_missing'],
            'seller mismatch' => ['MATCH', 'MATCH', 'FOREIGN_VALID', 'ksef_invoice_status_seller_mismatch'],
        ];
    }

    #[DataProvider('reconciliationFailureCases')]
    public function test_reconciliation_failure_keeps_uncertain_without_resend(
        ?array $failure,
        ?array $response,
        string $expectedError,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $service = app(KsefInvoiceSubmissionService::class);
        $submission = $service->prepare($invoice);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Uncertain,
            'session_reference_number' => '20260819-SO-TEST-REFERENCE',
            'invoice_reference_number' => null,
        ])->save();
        $path = '/sessions/20260819-SO-TEST-REFERENCE/invoices';
        if ($failure !== null) {
            $fake->failures[$path] = $failure;
        }
        if ($response !== null) {
            $fake->sessionInvoicesResponse = collect($response)
                ->map(function (mixed $value) use ($submission): mixed {
                    if (! is_array($value)) {
                        return $value;
                    }

                    return array_map(function (mixed $item) use ($submission): mixed {
                        if (! is_array($item)) {
                            return $item;
                        }

                        if (($item['invoiceHash'] ?? null) === 'MATCH_AT_RUNTIME') {
                            $item['invoiceHash'] = $submission->invoice_hash;
                        }

                        return $item;
                    }, $value);
                })
                ->all();
        }

        $this->expectKsefError($expectedError, fn () => $service->reconcile($submission));

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame(
            $expectedError === 'http_500' ? 'SYNTHETIC_FAILURE' : $expectedError,
            $submission->safe_error_code,
        );
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(0, $fake->sendCalls);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
    }

    public static function reconciliationFailureCases(): array
    {
        return [
            'connection error' => [['connection' => true], null, 'network_error'],
            'server error' => [['status' => 500], null, 'http_500'],
            'no matching invoice' => [null, ['invoices' => []], 'ksef_reconciliation_result_unresolved'],
            'incomplete pagination' => [null, [
                'invoices' => [],
                'continuationToken' => 'MORE-RESULTS',
            ], 'ksef_reconciliation_result_ambiguous'],
            'duplicate hash' => [null, ['invoices' => [
                ['referenceNumber' => 'REF-1', 'invoiceHash' => 'MATCH_AT_RUNTIME'],
                ['referenceNumber' => 'REF-2', 'invoiceHash' => 'MATCH_AT_RUNTIME'],
            ]], 'ksef_reconciliation_result_ambiguous'],
        ];
    }

    public function test_active_accepted_and_uncertain_attempts_block_duplicates_without_http(): void
    {
        foreach ([
            KsefInvoiceSubmissionStatus::Preparing,
            KsefInvoiceSubmissionStatus::SessionOpened,
            KsefInvoiceSubmissionStatus::Submitted,
            KsefInvoiceSubmissionStatus::Processing,
            KsefInvoiceSubmissionStatus::Accepted,
            KsefInvoiceSubmissionStatus::Uncertain,
        ] as $status) {
            $invoice = $this->eligibleInvoice();
            $submission = app(KsefInvoiceSubmissionService::class)->prepare($invoice);
            $submission->forceFill(['status' => $status])->save();

            $this->expectKsefError(
                $status === KsefInvoiceSubmissionStatus::Uncertain
                    ? 'ksef_submission_reconciliation_required'
                    : 'ksef_submission_already_exists',
                fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
            );
        }

        Http::assertNothingSent();
    }

    #[DataProvider('deletionBlockingStatuses')]
    public function test_any_submission_blocks_controlled_invoice_deletion(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = app(KsefInvoiceSubmissionService::class)->prepare($invoice);
        $submission->forceFill(['status' => $status])->save();

        $this->expectInvoiceError(
            'invoice_delete_blocked_by_ksef_submission',
            fn () => app(InvoiceDeletionService::class)->delete(
                $invoice,
                $invoice->lock_version,
                $this->documentContext(),
            ),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseHas('ksef_invoice_submissions', ['id' => $submission->getKey()]);
    }

    public static function deletionBlockingStatuses(): array
    {
        $statuses = [];
        foreach (KsefInvoiceSubmissionStatus::cases() as $status) {
            $statuses[$status->value] = [$status];
        }

        return $statuses;
    }

    public function test_bulk_delete_is_controlled_for_preparing_and_technical_submissions(): void
    {
        $preparingInvoice = $this->eligibleInvoice();
        $preparing = app(KsefInvoiceSubmissionService::class)->prepare($preparingInvoice);
        $failedInvoice = $this->eligibleInvoice();
        $failed = app(KsefInvoiceSubmissionService::class)->prepare($failedInvoice);
        $failed->forceFill(['status' => KsefInvoiceSubmissionStatus::TechnicalFailed])->save();

        $this->expectInvoiceError(
            'invoice_delete_blocked_by_ksef_submission',
            fn () => app(InvoiceDeletionService::class)->deleteMany(
                [
                    $preparingInvoice->getKey() => $preparingInvoice->lock_version,
                    $failedInvoice->getKey() => $failedInvoice->lock_version,
                ],
                $this->documentContext(),
            ),
        );

        $this->assertDatabaseHas('invoices', ['id' => $preparingInvoice->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $failedInvoice->getKey()]);
        $this->assertDatabaseHas('ksef_invoice_submissions', ['id' => $preparing->getKey()]);
        $this->assertDatabaseHas('ksef_invoice_submissions', ['id' => $failed->getKey()]);
    }

    private function eligibleInvoice(
        bool $finalize = true,
        KsefEnvironment $environment = KsefEnvironment::Test,
        string $contextNip = '9876543210',
        string $sellerNip = '9876543210',
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => $contextNip,
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-SUBMISSION-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
            'vat_rate' => '23.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false,
            'seller_tax_id' => $sellerNip,
        ]);
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        )->refresh()->load('items');

        return $finalize
            ? app(InvoiceFinalizationService::class)->finalize($invoice)->load('items')
            : $invoice;
    }

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_SUBMISSION_API_TOKEN',
            'access_token' => 'FAKE_VALID_SUBMISSION_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_VALID_SUBMISSION_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function validKsefNumber(string $sellerNip): string
    {
        $base = $sellerNip.'-20260819-0100001AF629';
        $checksum = 0;
        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    private function expectKsefError(string $code, callable $operation): KsefApiException
    {
        try {
            $operation();
            $this->fail('Expected KSeF error '.$code.'.');
        } catch (KsefApiException $exception) {
            $this->assertSame($code, $exception->safeCode);

            return $exception;
        }
    }

    private function expectInvoiceError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Expected invoice error '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
