<?php

namespace Tests\Support\Ksef;

use Carbon\CarbonImmutable;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefSubmissionExecution;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;

trait CreatesSubmissionRecoveryScenario
{
    use CreatesInvoiceStage2CDocuments;

    private function preparing(KsefEnvironment $environment = KsefEnvironment::Test): KsefInvoiceSubmission
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-19 10:30:00 UTC'));
        config()->set('ksef.invoice_submission_enabled', true);
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true, 'environment' => $environment, 'context_nip' => '9876543210',
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'FAKE-RECOVERY-'.uniqid(), 'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00', 'paid_amount' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00', 'total_price_gross' => '123.00', 'vat_rate' => '23.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false, 'seller_tax_id' => '9876543210',
        ]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $series->id, 'is_enabled' => true]);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext('2026-08-19 10:00:00'));

        return app(KsefInvoiceSubmissionService::class)->prepare(app(InvoiceFinalizationService::class)->finalize($invoice));
    }

    private function opened(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $execution = app(KsefSubmissionExecution::class);
        $submission = $execution->claim($submission);
        $submission = $execution->encryptionMetadata($submission, $submission->execution_owner, [
            'public_key_id' => 'FAKE-KEY-ID',
            'encrypted_invoice_hash' => base64_encode(hash('sha256', 'FAKE-CIPHERTEXT', true)),
            'encrypted_invoice_size' => strlen('FAKE-CIPHERTEXT'),
        ]);

        return $execution->phase($submission, $submission->execution_owner, Status::SessionOpened, [
            'session_reference_number' => 'FAKE-SESSION',
            'session_valid_until' => CarbonImmutable::now('UTC')->addHour(),
        ]);
    }

    private function requestFor(KsefInvoiceSubmission $submission): array
    {
        return [
            'offlineMode' => $submission->offline_issuance_id !== null,
            'invoiceHash' => $submission->invoice_hash,
            'invoiceSize' => $submission->invoice_size,
            'encryptedInvoiceHash' => $submission->encrypted_invoice_hash,
            'encryptedInvoiceSize' => $submission->encrypted_invoice_size,
            'encryptedInvoiceContent' => base64_encode('FAKE-CIPHERTEXT'),
        ];
    }
}
