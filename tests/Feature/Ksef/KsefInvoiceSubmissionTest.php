<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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

    public function test_prepare_rejects_disabled_transport_wrong_environment_and_unsupported_documents_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        config()->set('ksef.invoice_submission_enabled', false);
        $this->expectKsefError(
            'ksef_submission_disabled',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );

        config()->set('ksef.invoice_submission_enabled', true);
        foreach ([KsefEnvironment::Demo, KsefEnvironment::Production] as $environment) {
            app(KsefSettingsService::class)->get()->forceFill(['environment' => $environment])->save();
            $this->expectKsefError(
                'ksef_submission_environment_blocked',
                fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
            );
        }

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

    public function test_prepare_requires_finalized_invoice_active_ksef_and_enabled_series(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->expectInvoiceError(
            'ksef_fa3_document_not_finalized',
            fn () => app(KsefInvoiceSubmissionService::class)->prepare($invoice),
        );

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
                'ksef_submission_already_exists',
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
                'ksef_submission_already_exists',
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

    private function validAccessToken(): KsefCredential
    {
        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
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
