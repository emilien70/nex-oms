<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionPolicy;
use Modules\Ksef\Services\KsefEcdsaSignatureConverter;
use Modules\Ksef\Services\KsefInvoiceProvenanceService;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Modules\Ksef\Services\KsefMonthlyInvoiceExportService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflineIssuanceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $offlineFixture;

    private CarbonImmutable $testNow;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('ksef.invoice_submission_enabled', true);
        $this->offlineFixture = KsefCertificateFixtureFactory::offlineEc();
        $this->testNow = CarbonImmutable::createFromTimestamp(
            $this->offlineFixture['valid_from'],
            'UTC',
        )->addHour();
        $this->travelTo($this->testNow);
    }

    public function test_test_offline24_freezes_exact_encrypted_fa3_certificate_and_verification_links(): void
    {
        [$invoice, $certificate] = $this->eligibleInvoiceWithCertificate();

        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);
        $xml = $issuance->payload_xml;
        $expectedHash = base64_encode(hash('sha256', $xml, true));
        $hashUrl = $this->base64Url(base64_decode($expectedHash, true));
        $date = $this->testNow->setTimezone('Europe/Warsaw')->toDateString();
        $rawPayload = DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->value('payload_xml');

        $this->assertTrue(Schema::hasColumns('ksef_offline_issuances', [
            'invoice_id',
            'environment',
            'procedure',
            'issue_date',
            'issued_at',
            'seller_nip',
            'context_identifier_type',
            'context_identifier_value',
            'payload_xml',
            'invoice_hash',
            'invoice_size',
            'offline_certificate_id',
            'certificate_serial_number',
            'certificate_fingerprint_sha256',
            'certificate_remote_status',
            'invoice_verification_url',
            'certificate_verification_url',
            'latarnia_source_environment',
            'latarnia_trigger_event_id',
            'latarnia_trigger_message_id',
            'latarnia_trigger_message_version',
            'latarnia_trigger_category',
            'latarnia_trigger_start_at',
            'latarnia_trigger_end_at',
            'latarnia_trigger_published_at',
            'latarnia_evidence_as_of_at',
            'latarnia_evidence_from_at',
            'latarnia_evidence_through_at',
        ]));
        $this->assertSame(KsefEnvironment::Test, $issuance->environment);
        $this->assertSame(KsefOfflineIssuanceProcedure::Offline24, $issuance->procedure);
        $this->assertSame($date, $issuance->issue_date->toDateString());
        $this->assertSame($this->testNow->getTimestamp(), $issuance->issued_at->getTimestamp());
        $this->assertSame('9876543210', $issuance->seller_nip);
        $this->assertSame(KsefContextIdentifierType::Nip, $issuance->context_identifier_type);
        $this->assertSame('9876543210', $issuance->context_identifier_value);
        $this->assertSame('FA (3) 1-0E', $issuance->schema_id);
        $this->assertStringContainsString('<Faktura', $xml);
        $this->assertSame($expectedHash, $issuance->invoice_hash);
        $this->assertSame(strlen($xml), $issuance->invoice_size);
        $this->assertSame($certificate->getKey(), $issuance->offline_certificate_id);
        $this->assertSame($certificate->certificate_serial_number, $issuance->certificate_serial_number);
        $this->assertSame($certificate->fingerprint_sha256, $issuance->certificate_fingerprint_sha256);
        $this->assertEquals($certificate->valid_from, $issuance->certificate_valid_from);
        $this->assertEquals($certificate->valid_until, $issuance->certificate_valid_until);
        $this->assertSame('Active', $issuance->certificate_remote_status);
        $this->assertEquals($certificate->remote_valid_from, $issuance->certificate_remote_valid_from);
        $this->assertEquals($certificate->remote_valid_until, $issuance->certificate_remote_valid_until);
        $this->assertEquals($certificate->remote_verified_at, $issuance->certificate_remote_verified_at);
        $this->assertNotSame($xml, $rawPayload);
        $this->assertStringNotContainsString('<Faktura', $rawPayload);
        $this->assertStringNotContainsString('9876543210', $rawPayload);
        $this->assertArrayNotHasKey('payload_xml', $issuance->toArray());
        $this->assertSame(
            config('ksef.qr_base_urls.test').'/invoice/9876543210/'
                .$this->testNow->setTimezone('Europe/Warsaw')->format('d-m-Y').'/'.$hashUrl,
            $issuance->invoice_verification_url,
        );
        $this->assertStringContainsString(
            '/certificate/Nip/9876543210/9876543210/'
                .$certificate->certificate_serial_number.'/'.$hashUrl.'/',
            $issuance->certificate_verification_url,
        );
        $this->assertStringNotContainsString('=', parse_url($issuance->invoice_verification_url, PHP_URL_PATH));
        $this->assertNull($issuance->latarnia_source_environment);
        $this->assertNull($issuance->latarnia_trigger_event_id);
        $this->assertNull($issuance->latarnia_trigger_message_id);
        $this->assertKodIiSignatureIsValid($issuance->certificate_verification_url);
        $this->assertTrue($invoice->ksefOfflineIssuances()->firstOrFail()->is($issuance));
        $this->assertTrue($issuance->invoice->is($invoice));
        $this->assertTrue($issuance->offlineCertificate->is($certificate));
        $this->assertDatabaseCount('ksef_offline_issuances', 1);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        $this->assertFalse(Schema::hasColumn('ksef_offline_issuances', 'private_key_pem'));
        $this->assertFalse(Schema::hasColumn('ksef_offline_issuances', 'certificate_pem'));
        Http::assertNothingSent();
    }

    public function test_frozen_offline24_snapshot_does_not_follow_later_source_or_configuration_changes(): void
    {
        [$invoice, $certificate] = $this->eligibleInvoiceWithCertificate();
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);
        $before = $this->issuanceSnapshot($issuance);

        $invoice->forceFill(['additional_information_text' => 'LATER INVOICE CHANGE'])->saveQuietly();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
            'context_nip' => '5260250995',
        ])->save();
        $certificate->forceFill([
            'remote_status' => 'Revoked',
            'remote_valid_until' => $this->testNow->subDay(),
        ])->save();
        $this->readyCertificate(
            KsefEnvironment::Test,
            fixture: KsefCertificateFixtureFactory::offlineEc(serial: 0x08F20A5D352AE599),
        );

        $this->assertSame($before, $this->issuanceSnapshot($issuance->fresh()));
        $this->assertDatabaseCount('ksef_offline_issuances', 1);
        Http::assertNothingSent();
    }

    public function test_demo_offline24_uses_only_demo_selection_and_demo_qr_hosts(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate(KsefEnvironment::Demo);

        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->assertSame(KsefEnvironment::Demo, $issuance->environment);
        $this->assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/invoice/', $issuance->invoice_verification_url);
        $this->assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/certificate/', $issuance->certificate_verification_url);
        Http::assertNothingSent();
    }

    public function test_demo_never_falls_back_to_preferred_test_certificate(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Demo);
        $this->readyCertificate(KsefEnvironment::Test);

        $this->expectKsefError(
            'ksef_offline24_preferred_certificate_missing',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public function test_production_is_blocked_before_certificate_lookup_or_http(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Production);

        $this->expectKsefError(
            'ksef_operational_environment_blocked',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('nonCurrentIssueDates')]
    public function test_offline24_requires_p1_to_match_one_captured_warsaw_day(string $modifier): void
    {
        $issueDate = $this->testNow->setTimezone('Europe/Warsaw')->{$modifier}()->toDateString();
        [$invoice] = $this->eligibleInvoiceWithCertificate(issueDate: $issueDate);

        $this->expectKsefError(
            'ksef_offline24_issue_date_not_today',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function nonCurrentIssueDates(): array
    {
        return [
            'yesterday' => ['subDay'],
            'tomorrow' => ['addDay'],
        ];
    }

    public function test_warsaw_day_is_used_when_utc_is_still_on_the_previous_date(): void
    {
        $warsawEdge = CarbonImmutable::createFromTimestamp(
            $this->offlineFixture['valid_from'],
            'UTC',
        )->setTimezone('Europe/Warsaw')->startOfDay()->addDay()->addMinutes(30);
        $this->travelTo($warsawEdge);
        [$invoice] = $this->eligibleInvoiceWithCertificate(issueDate: $warsawEdge->toDateString());

        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->assertNotSame(
            $issuance->issued_at->setTimezone('UTC')->toDateString(),
            $issuance->issued_at->setTimezone('Europe/Warsaw')->toDateString(),
        );
        $this->assertSame($warsawEdge->toDateString(), $issuance->issue_date->toDateString());
    }

    #[DataProvider('invalidContextCases')]
    public function test_context_is_required_and_delegated_context_is_blocked(
        ?string $contextNip,
        string $expectedCode,
    ): void {
        [$invoice] = $this->eligibleInvoiceWithCertificate(contextNip: $contextNip);

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function invalidContextCases(): array
    {
        return [
            'missing' => [null, 'ksef_offline24_context_missing'],
            'invalid' => ['123', 'ksef_offline24_context_missing'],
            'delegated' => ['5260250995', 'ksef_offline24_delegated_context_not_supported'],
        ];
    }

    #[DataProvider('certificateNotReadyCases')]
    public function test_preferred_certificate_must_be_exact_and_ready(string $change): void
    {
        [$invoice, $certificate] = $this->eligibleInvoiceWithCertificate();

        match ($change) {
            'remote verification missing' => $certificate->forceFill(['remote_verified_at' => null])->save(),
            'revoked' => $certificate->forceFill(['remote_status' => 'Revoked'])->save(),
            'blocked' => $certificate->forceFill(['remote_status' => 'Blocked'])->save(),
            'unknown status' => $certificate->forceFill(['remote_status' => 'Unexpected'])->save(),
            'expired local' => $certificate->forceFill(['valid_until' => $this->testNow->subMinute()])->save(),
            'future local' => $certificate->forceFill(['valid_from' => $this->testNow->addDay()])->save(),
            'expired remote' => $certificate->forceFill(['remote_valid_until' => $this->testNow->subMinute()])->save(),
            'future remote' => $certificate->forceFill(['remote_valid_from' => $this->testNow->addDay()])->save(),
            'broken private key' => $certificate->forceFill([
                'private_key_pem' => KsefCertificateFixtureFactory::offlineEc(
                    serial: 0x08F20A5D352AE599,
                )['private_key'],
            ])->save(),
        };

        $this->expectKsefError(
            'ksef_offline24_certificate_not_ready',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function certificateNotReadyCases(): array
    {
        return [
            'remote verification missing' => ['remote verification missing'],
            'revoked' => ['revoked'],
            'blocked' => ['blocked'],
            'unknown status' => ['unknown status'],
            'expired local' => ['expired local'],
            'future local' => ['future local'],
            'expired remote' => ['expired remote'],
            'future remote' => ['future remote'],
            'broken private key' => ['broken private key'],
        ];
    }

    public function test_missing_and_cross_environment_preferred_certificates_are_blocked(): void
    {
        $invoice = $this->eligibleInvoice();

        $this->expectKsefError(
            'ksef_offline24_preferred_certificate_missing',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $demoCertificate = $this->readyCertificate(KsefEnvironment::Demo, preferred: false);
        KsefOfflineCertificateSelection::query()->create([
            'environment' => KsefEnvironment::Test,
            'offline_certificate_id' => $demoCertificate->getKey(),
        ]);

        $this->expectKsefError(
            'ksef_offline24_certificate_environment_mismatch',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );
        Http::assertNothingSent();
    }

    #[DataProvider('submissionStatuses')]
    public function test_any_same_environment_online_history_blocks_offline24(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, KsefEnvironment::Test, $status);

        $this->expectKsefError(
            'ksef_offline24_submission_history_exists',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function submissionStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    public function test_outside_ksef_is_mutually_exclusive_per_environment(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        KsefInvoiceProvenance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Demo,
            'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
            'recorded_at' => $this->testNow,
        ]);

        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->assertSame(KsefEnvironment::Test, $issuance->environment);
        $this->expectInvoiceError(
            'ksef_invoice_provenance_offline_issuance_exists',
            fn () => app(KsefInvoiceProvenanceService::class)->markOutsideKsef(
                $invoice,
                KsefEnvironment::Test,
            ),
        );
        $this->assertDatabaseCount('ksef_invoice_provenances', 1);
        Http::assertNothingSent();
    }

    public function test_same_environment_outside_ksef_blocks_offline24(): void
    {
        $invoice = $this->eligibleInvoice();
        app(KsefInvoiceProvenanceService::class)->markOutsideKsef($invoice, KsefEnvironment::Test);

        $this->expectKsefError(
            'ksef_offline24_outside_ksef_provenance_exists',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
    }

    public function test_offline24_blocks_central_manual_and_automatic_online_paths_without_http(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->expectKsefError(
            'ksef_submission_blocked_by_offline_issuance',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );
        $this->expectKsefError(
            'ksef_submission_blocked_by_offline_issuance',
            fn () => app(KsefManualInvoiceSubmissionService::class)->submit($invoice),
        );
        $this->assertNull(app(KsefAutomaticInvoiceSubmissionPolicy::class)->snapshotFor($invoice));
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_monthly_online_export_excludes_an_offline24_invoice_before_dispatch(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);
        $manual = Mockery::mock(KsefManualInvoiceSubmissionService::class);
        $manual->shouldNotReceive('submitFirstAttempt');
        $this->app->instance(KsefManualInvoiceSubmissionService::class, $manual);

        $result = app(KsefMonthlyInvoiceExportService::class)->export(
            $this->testNow->setTimezone('Europe/Warsaw')->format('Y-m'),
        );

        $this->assertSame(0, $result->eligibleCount);
        $this->assertSame(0, $result->submittedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_is_controlled_and_database_unique_is_final_guard(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->expectKsefError(
            'ksef_offline24_already_issued',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $row = (array) DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->first();
        unset($row['id']);

        try {
            DB::table('ksef_offline_issuances')->insert($row);
            $this->fail('Expected the database unique constraint to reject a duplicate Offline24 issuance.');
        } catch (QueryException) {
            $this->assertDatabaseCount('ksef_offline_issuances', 1);
        }
    }

    #[DataProvider('configurationRaceCases')]
    public function test_configuration_and_history_races_never_persist_a_stale_issuance(
        string $change,
        string $expectedCode,
    ): void {
        [$invoice, $certificate] = $this->eligibleInvoiceWithCertificate();
        $realGenerator = app(KsefFa3DocumentGenerator::class);
        $mockGenerator = Mockery::mock(KsefFa3DocumentGenerator::class);
        $mockGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (
                Invoice $managed,
                $generatedAt,
                KsefFa3EligibilityMode $mode,
            ) use ($realGenerator, $change, $invoice, $certificate) {
                $generated = $realGenerator->generate($managed, $generatedAt, $mode);
                $this->applyRaceChange($change, $invoice, $certificate);

                return $generated;
            });
        $this->app->instance(KsefFa3DocumentGenerator::class, $mockGenerator);

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function configurationRaceCases(): array
    {
        return [
            'environment changed' => ['environment', 'ksef_offline24_configuration_changed'],
            'context changed' => ['context', 'ksef_offline24_configuration_changed'],
            'series disabled' => ['series', 'ksef_offline24_configuration_changed'],
            'invoice changed' => ['invoice', 'ksef_offline24_configuration_changed'],
            'certificate identity changed' => ['certificate_identity', 'ksef_offline24_configuration_changed'],
            'certificate trust changed' => ['certificate_readiness', 'ksef_offline24_configuration_changed'],
            'preferred certificate changed' => ['selection', 'ksef_offline24_configuration_changed'],
            'online history appeared' => ['submission', 'ksef_offline24_submission_history_exists'],
            'outside provenance appeared' => ['provenance', 'ksef_offline24_outside_ksef_provenance_exists'],
        ];
    }

    public function test_invoice_delete_is_blocked_and_historical_certificate_delete_is_non_destructive(): void
    {
        [$invoice, $certificate] = $this->eligibleInvoiceWithCertificate();
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);
        $snapshot = $issuance->only([
            'certificate_serial_number',
            'certificate_fingerprint_sha256',
            'certificate_remote_status',
            'invoice_hash',
        ]);

        $this->expectInvoiceError(
            'invoice_delete_blocked_by_ksef_offline_issuance',
            fn () => app(InvoiceDeletionService::class)->delete(
                $invoice,
                $invoice->lock_version,
                $this->documentContext($this->testNow->format('Y-m-d H:i:s')),
            ),
        );

        $otherFixture = KsefCertificateFixtureFactory::offlineEc(serial: 0x08F20A5D352AE599);
        $other = $this->readyCertificate(KsefEnvironment::Test, fixture: $otherFixture);
        app(KsefOfflineCertificateService::class)->setPreferred($other, KsefEnvironment::Test);
        app(KsefOfflineCertificateService::class)->delete($certificate);
        $issuance->refresh();

        $this->assertNull($issuance->offline_certificate_id);
        $this->assertSame($snapshot, $issuance->only(array_keys($snapshot)));
        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseHas('ksef_offline_issuances', ['id' => $issuance->getKey()]);
    }

    public function test_offline_issuance_model_cannot_be_updated_or_deleted(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        try {
            $issuance->forceFill(['seller_nip' => '5260250995'])->save();
            $this->fail('Expected immutable Offline24 issuance update to be rejected.');
        } catch (DomainException) {
            $this->assertSame('9876543210', $issuance->fresh()->seller_nip);
        }

        try {
            $issuance->fresh()->delete();
            $this->fail('Expected immutable Offline24 issuance deletion to be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseHas('ksef_offline_issuances', ['id' => $issuance->getKey()]);
        }
    }

    public function test_ui_offers_post_only_offline24_and_shows_safe_local_status_after_issue(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $confirmation = 'Wystawienie w trybie Offline24 tworzy Fakturę przed przesłaniem do KSeF.';

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('WYSTAW OFFLINE24')
            ->assertSee($confirmation)
            ->assertSee('method="POST"', false)
            ->assertSee(route('invoices.ksef.offline24.issue', $invoice), false);
        Http::assertNothingSent();

        $this->post(route('invoices.ksef.offline24.issue', $invoice))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Faktura została wystawiona lokalnie w trybie Offline24.');

        $issuance = KsefOfflineIssuance::query()->firstOrFail();
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Offline24 — wystawiona lokalnie')
            ->assertSee('Numer KSeF:')
            ->assertSee('jeszcze nie nadano')
            ->assertSee($issuance->certificate_serial_number)
            ->assertDontSee('WYSTAW OFFLINE24')
            ->assertDontSee($issuance->payload_xml)
            ->assertDontSee($issuance->invoice_hash)
            ->assertDontSee($issuance->certificate_fingerprint_sha256)
            ->assertDontSee($issuance->certificate_verification_url);
        Http::assertNothingSent();
    }

    public function test_ui_hides_offline24_for_production_and_unfinalized_invoices(): void
    {
        [$finalized] = $this->eligibleInvoiceWithCertificate();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Production,
        ])->save();

        $this->get(route('invoices.edit', $finalized))
            ->assertOk()
            ->assertDontSee('WYSTAW OFFLINE24');

        $unfinalized = $this->eligibleInvoice(KsefEnvironment::Test, finalize: false);
        $this->get(route('invoices.edit', $unfinalized))
            ->assertOk()
            ->assertDontSee('WYSTAW OFFLINE24');

        Http::assertNothingSent();
    }

    public function test_planned_unavailability_uses_only_fresh_local_maintenance_evidence_and_freezes_provenance(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $state = $this->latarniaState(KsefLatarniaStatus::Maintenance);
        $message = $this->latarniaMessage([
            'event_id' => 701,
            'external_message_id' => 'MAINTENANCE-701',
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'start_at' => $this->testNow->subHour(),
            'end_at' => $this->testNow->addHours(2),
            'published_at' => $this->testNow->subHours(2),
            'first_fetched_at' => $this->testNow->subMinutes(10),
        ]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('WYSTAW OFFLINE – NIEDOSTĘPNOŚĆ')
            ->assertSee('Przerwa KSeF do')
            ->assertSee($message->end_at->setTimezone(config('app.timezone'))->format('d.m.Y H:i'))
            ->assertSee('data-ksef-offline-unavailability-form', false)
            ->assertSee('data-ksef-emergency-disabled', false);

        $issuance = app(KsefOfflineIssuanceService::class)->issuePlannedUnavailability($invoice)->fresh();

        $this->assertSame(KsefOfflineIssuanceProcedure::PlannedUnavailability, $issuance->procedure);
        $this->assertSame(KsefLatarniaEnvironment::Test, $issuance->latarnia_source_environment);
        $this->assertSame(701, $issuance->latarnia_trigger_event_id);
        $this->assertSame('MAINTENANCE-701', $issuance->latarnia_trigger_message_id);
        $this->assertSame(1, $issuance->latarnia_trigger_message_version);
        $this->assertSame('MAINTENANCE', $issuance->latarnia_trigger_category->value);
        $this->assertTrue($issuance->latarnia_trigger_start_at->equalTo($message->start_at));
        $this->assertTrue($issuance->latarnia_trigger_end_at->equalTo($message->end_at));
        $this->assertTrue($issuance->latarnia_trigger_published_at->equalTo($message->published_at));
        $this->assertTrue($issuance->latarnia_evidence_from_at->equalTo($state->messages_coverage_from_at));
        $this->assertTrue($issuance->latarnia_evidence_through_at->equalTo($state->messages_coverage_through_at));
        $this->assertStringContainsString('<Faktura', $issuance->payload_xml);
        $this->assertSame(base64_encode(hash('sha256', $issuance->payload_xml, true)), $issuance->invoice_hash);
        $this->assertSame(strlen($issuance->payload_xml), $issuance->invoice_size);
        $this->assertDatabaseCount('ksef_offline_issuances', 1);
        Http::assertNothingSent();
    }

    public function test_failure_uses_only_fresh_local_ordinary_failure_evidence(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $this->latarniaState(KsefLatarniaStatus::Failure);
        $message = $this->latarniaMessage([
            'event_id' => 702,
            'external_message_id' => 'FAILURE-702',
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'start_at' => $this->testNow->subHour(),
            'end_at' => null,
            'published_at' => $this->testNow->subMinutes(30),
            'first_fetched_at' => $this->testNow->subMinutes(10),
        ]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('data-ksef-emergency-form', false)
            ->assertSee('data-ksef-offline-unavailability-disabled', false);

        $issuance = app(KsefOfflineIssuanceService::class)->issueFailure($invoice)->fresh();

        $this->assertSame(KsefOfflineIssuanceProcedure::Failure, $issuance->procedure);
        $this->assertSame(702, $issuance->latarnia_trigger_event_id);
        $this->assertSame($message->external_message_id, $issuance->latarnia_trigger_message_id);
        $this->assertNull($issuance->latarnia_trigger_end_at);
        $this->assertDatabaseCount('ksef_offline_issuances', 1);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('localProcedureEligibilityFailures')]
    public function test_event_dependent_procedures_fail_closed_without_valid_fresh_local_evidence(
        string $scenario,
        string $method,
        string $expectedCode,
    ): void {
        [$invoice] = $this->eligibleInvoiceWithCertificate(
            $scenario === 'demo' ? KsefEnvironment::Demo : KsefEnvironment::Test,
        );

        if ($scenario !== 'demo') {
            $state = $this->latarniaState(match ($scenario) {
                'maintenance stale', 'maintenance outside window', 'maintenance ambiguous' => KsefLatarniaStatus::Maintenance,
                'failure ended' => KsefLatarniaStatus::Failure,
                'total failure' => KsefLatarniaStatus::TotalFailure,
                default => KsefLatarniaStatus::Available,
            });

            if ($scenario === 'maintenance stale') {
                $state->forceFill([
                    'status_last_success_at' => $this->testNow->subHour(),
                    'messages_last_success_at' => $this->testNow->subHour(),
                    'messages_coverage_through_at' => $this->testNow->subHour(),
                ])->save();
            }

            if ($scenario === 'maintenance outside window') {
                $this->latarniaMessage([
                    'category' => 'MAINTENANCE',
                    'type' => 'MAINTENANCE_ANNOUNCEMENT',
                    'start_at' => $this->testNow->addHour(),
                    'end_at' => $this->testNow->addHours(2),
                ]);
            }

            if ($scenario === 'maintenance ambiguous') {
                foreach ([901, 902] as $eventId) {
                    $this->latarniaMessage([
                        'event_id' => $eventId,
                        'external_message_id' => 'MAINTENANCE-'.$eventId,
                        'category' => 'MAINTENANCE',
                        'type' => 'MAINTENANCE_ANNOUNCEMENT',
                        'start_at' => $this->testNow->subHour(),
                        'end_at' => $this->testNow->addHour(),
                    ]);
                }
            }

            if ($scenario === 'failure ended') {
                $this->latarniaMessage([
                    'category' => 'FAILURE',
                    'type' => 'FAILURE_START',
                    'start_at' => $this->testNow->subHours(2),
                    'end_at' => $this->testNow->subMinute(),
                ]);
            }
        }

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineIssuanceService::class)->{$method}($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function localProcedureEligibilityFailures(): array
    {
        return [
            'DEMO planned' => ['demo', 'issuePlannedUnavailability', 'ksef_offline_procedure_unsupported_environment'],
            'DEMO failure' => ['demo', 'issueFailure', 'ksef_offline_procedure_unsupported_environment'],
            'stale maintenance' => ['maintenance stale', 'issuePlannedUnavailability', 'ksef_offline_procedure_latarnia_stale'],
            'maintenance outside window' => ['maintenance outside window', 'issuePlannedUnavailability', 'ksef_offline_procedure_event_missing'],
            'ambiguous maintenance' => ['maintenance ambiguous', 'issuePlannedUnavailability', 'ksef_offline_procedure_latarnia_ambiguous'],
            'ended failure' => ['failure ended', 'issueFailure', 'ksef_offline_procedure_event_missing'],
            'status mismatch' => ['status mismatch', 'issueFailure', 'ksef_offline_procedure_status_mismatch'],
            'total failure' => ['total failure', 'issueFailure', 'ksef_offline_procedure_status_mismatch'],
        ];
    }

    public function test_latarnia_evidence_change_during_generation_prevents_issuance(): void
    {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $state = $this->latarniaState(KsefLatarniaStatus::Maintenance);
        $this->latarniaMessage([
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'start_at' => $this->testNow->subHour(),
            'end_at' => $this->testNow->addHours(2),
        ]);
        $realGenerator = app(KsefFa3DocumentGenerator::class);
        $mockGenerator = Mockery::mock(KsefFa3DocumentGenerator::class);
        $mockGenerator->shouldReceive('generate')->once()->andReturnUsing(
            function (Invoice $managed, $generatedAt, KsefFa3EligibilityMode $mode) use ($realGenerator, $state) {
                $generated = $realGenerator->generate($managed, $generatedAt, $mode);
                $state->forceFill(['current_status' => KsefLatarniaStatus::Available])->save();

                return $generated;
            },
        );
        $this->app->instance(KsefFa3DocumentGenerator::class, $mockGenerator);

        $this->expectKsefError(
            'ksef_offline_procedure_status_mismatch',
            fn () => app(KsefOfflineIssuanceService::class)->issuePlannedUnavailability($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('unsupportedDocumentTypes')]
    public function test_offline24_rejects_an_unsupported_document_type(
        InvoiceDocumentType $documentType,
    ): void {
        [$invoice] = $this->eligibleInvoiceWithCertificate();
        $invoice->forceFill([
            'document_type' => $documentType,
        ])->saveQuietly();

        $this->expectKsefError(
            'ksef_offline24_document_not_eligible',
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function unsupportedDocumentTypes(): array
    {
        return [
            'proforma' => [InvoiceDocumentType::Proforma],
            'correction' => [InvoiceDocumentType::Correction],
        ];
    }

    #[DataProvider('initialEligibilityFailures')]
    public function test_initial_domain_and_configuration_gates_fail_closed(
        string $change,
        string $expectedCode,
    ): void {
        [$invoice] = $this->eligibleInvoiceWithCertificate();

        match ($change) {
            'unfinalized' => $invoice->forceFill(['finalized_at' => null])->saveQuietly(),
            'inactive settings' => app(KsefSettingsService::class)->get()
                ->forceFill(['is_active' => false])
                ->save(),
            'disabled series' => KsefSeriesSetting::query()
                ->where('invoice_series_id', $invoice->invoice_series_id)
                ->update(['is_enabled' => false]),
        };

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineIssuanceService::class)->issueOffline24($invoice),
        );

        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    public static function initialEligibilityFailures(): array
    {
        return [
            'unfinalized invoice' => ['unfinalized', 'ksef_offline24_document_not_eligible'],
            'inactive KSeF' => ['inactive settings', 'ksef_offline24_configuration_inactive'],
            'series outside KSeF' => ['disabled series', 'ksef_offline24_series_disabled'],
        ];
    }

    /** @return array{0: Invoice, 1: KsefOfflineCertificate} */
    private function eligibleInvoiceWithCertificate(
        KsefEnvironment $environment = KsefEnvironment::Test,
        ?string $issueDate = null,
        ?string $contextNip = '9876543210',
    ): array {
        $invoice = $this->eligibleInvoice(
            $environment,
            contextNip: $contextNip,
            issueDate: $issueDate,
        );
        $certificate = $this->readyCertificate($environment);

        return [$invoice, $certificate];
    }

    private function eligibleInvoice(
        KsefEnvironment $environment = KsefEnvironment::Test,
        bool $finalize = true,
        ?string $contextNip = '9876543210',
        string $sellerNip = '9876543210',
        ?string $issueDate = null,
    ): Invoice {
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => $contextNip,
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-OFFLINE24-'.uniqid(),
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
        $date = $issueDate ?? $this->testNow->setTimezone(config('app.timezone'))->toDateString();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext($date.' 10:00:00'),
        )->refresh()->load('items');

        return $finalize
            ? app(InvoiceFinalizationService::class)->finalize($invoice)->load('items')
            : $invoice;
    }

    /** @param array<string, mixed>|null $fixture */
    private function readyCertificate(
        KsefEnvironment $environment,
        bool $preferred = true,
        ?array $fixture = null,
    ): KsefOfflineCertificate {
        $fixture ??= $this->offlineFixture;
        $certificate = app(KsefOfflineCertificateService::class)->import(
            $environment,
            'Offline24 test certificate',
            $fixture['certificate'],
            $fixture['private_key'],
            null,
        );
        $certificate->forceFill([
            'remote_status' => 'Active',
            'remote_certificate_name' => 'Offline24 test certificate',
            'remote_valid_from' => $this->testNow->subDay(),
            'remote_valid_until' => $this->testNow->addDay(),
            'remote_verified_at' => $this->testNow->subMinute(),
        ])->save();

        if ($preferred) {
            app(KsefOfflineCertificateService::class)->setPreferred($certificate, $environment);
        }

        return $certificate->fresh();
    }

    private function createSubmission(
        Invoice $invoice,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status = KsefInvoiceSubmissionStatus::Preparing,
    ): KsefInvoiceSubmission {
        $xml = '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => $this->testNow,
            'payload_xml' => $xml,
            'invoice_hash' => base64_encode(hash('sha256', $xml, true)),
            'invoice_size' => strlen($xml),
        ]);
    }

    private function latarniaState(
        KsefLatarniaStatus $status,
        KsefLatarniaEnvironment $environment = KsefLatarniaEnvironment::Test,
    ): KsefLatarniaSyncState {
        return KsefLatarniaSyncState::query()->create([
            'source_environment' => $environment,
            'current_status' => $status,
            'status_payload_json' => '{}',
            'status_payload_hash' => hash('sha256', '{}'),
            'status_last_success_at' => $this->testNow->subMinutes(2),
            'messages_last_success_at' => $this->testNow->subMinutes(2),
            'messages_coverage_from_at' => $this->testNow->subDay(),
            'messages_coverage_through_at' => $this->testNow->subMinute(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function latarniaMessage(array $overrides = []): KsefLatarniaMessage
    {
        static $sequence = 800;
        $sequence++;

        return KsefLatarniaMessage::query()->create(array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'LOCAL-'.$sequence,
            'event_id' => $sequence,
            'version' => 1,
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'title' => 'Synthetic local Latarnia event',
            'text' => 'Synthetic local Latarnia fixture.',
            'start_at' => $this->testNow->subHour(),
            'end_at' => null,
            'published_at' => $this->testNow->subMinutes(30),
            'payload_json' => '{}',
            'payload_hash' => base64_encode(hash('sha256', '{}', true)),
            'first_fetched_at' => $this->testNow->subMinutes(10),
            'last_seen_at' => $this->testNow->subMinute(),
        ], $overrides));
    }

    private function applyRaceChange(
        string $change,
        Invoice $invoice,
        KsefOfflineCertificate $certificate,
    ): void {
        match ($change) {
            'environment' => app(KsefSettingsService::class)->get()->forceFill([
                'environment' => KsefEnvironment::Demo,
            ])->save(),
            'context' => app(KsefSettingsService::class)->get()->forceFill([
                'context_nip' => '5260250995',
            ])->save(),
            'series' => KsefSeriesSetting::query()
                ->where('invoice_series_id', $invoice->invoice_series_id)
                ->update(['is_enabled' => false]),
            'invoice' => $invoice->forceFill(['additional_information_text' => 'RACE'])->save(),
            'certificate_identity' => $certificate->forceFill([
                'fingerprint_sha256' => str_repeat('B', 64),
            ])->save(),
            'certificate_readiness' => $certificate->forceFill(['remote_status' => 'Revoked'])->save(),
            'selection' => $this->readyCertificate(
                KsefEnvironment::Test,
                fixture: KsefCertificateFixtureFactory::offlineEc(serial: 0x08F20A5D352AE599),
            ),
            'submission' => $this->createSubmission($invoice, KsefEnvironment::Test),
            'provenance' => KsefInvoiceProvenance::query()->create([
                'invoice_id' => $invoice->getKey(),
                'environment' => KsefEnvironment::Test,
                'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
                'recorded_at' => $this->testNow,
            ]),
        };
    }

    private function assertKodIiSignatureIsValid(string $url): void
    {
        $separator = strrpos($url, '/');
        $signatureUrl = substr($url, $separator + 1);
        $unsignedUrl = substr($url, 0, $separator);
        $preSign = substr($unsignedUrl, strlen('https://'));
        $rawSignature = $this->base64UrlDecode($signatureUrl);
        $derSignature = app(KsefEcdsaSignatureConverter::class)->rawToDer($rawSignature);

        $this->assertSame(64, strlen($rawSignature));
        $this->assertSame(1, openssl_verify(
            $preSign,
            $derSignature,
            $this->offlineFixture['certificate'],
            OPENSSL_ALGO_SHA256,
        ));
    }

    /** @return array<string, int|string|null> */
    private function issuanceSnapshot(KsefOfflineIssuance $issuance): array
    {
        return [
            'payload_xml' => $issuance->payload_xml,
            'invoice_hash' => $issuance->invoice_hash,
            'invoice_size' => $issuance->invoice_size,
            'issue_date' => $issuance->issue_date->toDateString(),
            'issued_at' => $issuance->issued_at->format('Y-m-d H:i:sP'),
            'seller_nip' => $issuance->seller_nip,
            'context_identifier_type' => $issuance->context_identifier_type->value,
            'context_identifier_value' => $issuance->context_identifier_value,
            'certificate_serial_number' => $issuance->certificate_serial_number,
            'certificate_fingerprint_sha256' => $issuance->certificate_fingerprint_sha256,
            'certificate_valid_from' => $issuance->certificate_valid_from->format('Y-m-d H:i:sP'),
            'certificate_valid_until' => $issuance->certificate_valid_until->format('Y-m-d H:i:sP'),
            'certificate_remote_status' => $issuance->certificate_remote_status,
            'certificate_remote_valid_from' => $issuance->certificate_remote_valid_from->format('Y-m-d H:i:sP'),
            'certificate_remote_valid_until' => $issuance->certificate_remote_valid_until->format('Y-m-d H:i:sP'),
            'certificate_remote_verified_at' => $issuance->certificate_remote_verified_at->format('Y-m-d H:i:sP'),
            'invoice_verification_url' => $issuance->invoice_verification_url,
            'certificate_verification_url' => $issuance->certificate_verification_url,
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );
    }

    private function expectKsefError(string $safeCode, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected KSeF error [{$safeCode}].");
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);
        }
    }

    private function expectInvoiceError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected Invoice error [{$errorCode}].");
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($errorCode, $exception->errorCode());
        }
    }
}
