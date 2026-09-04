<?php

namespace Modules\Ksef\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSetting;

final class KsefOfflineInvoiceSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly KsefOfflineSubmissionIntegrityService $integrity,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function submitAttempt(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
    ): KsefInvoiceSubmission {
        $submission = $this->prepare($invoice, $issuance);

        return $this->submissions->submitOffline($submission);
    }

    public function prepare(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
    ): KsefInvoiceSubmission {
        $this->assertTransportEnabled();

        try {
            return DB::transaction(function () use ($invoice, $issuance): KsefInvoiceSubmission {
                $managedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->findOrFail($invoice->getKey());
                $managedIssuance = KsefOfflineIssuance::query()
                    ->lockForUpdate()
                    ->findOrFail($issuance->getKey());

                if ($managedIssuance->invoice_id !== $managedInvoice->getKey()) {
                    throw new KsefApiException(
                        'Wystawienie Offline nie należy do wskazanej Faktury.',
                        'ksef_offline_submission_issuance_mismatch',
                    );
                }

                $this->integrity->assertIssuance($managedIssuance, $managedInvoice);
                $this->environments->assertAllowed($managedIssuance->environment);
                $this->assertCurrentConfiguration($managedIssuance);

                $history = KsefInvoiceSubmission::query()
                    ->where('invoice_id', $managedInvoice->getKey())
                    ->where('environment', $managedIssuance->environment->value)
                    ->lockForUpdate()
                    ->get(['id', 'offline_issuance_id', 'status']);
                $this->assertNewAttemptAllowed($history, $managedIssuance);

                $attemptNumber = ((int) KsefInvoiceSubmission::query()
                    ->where('invoice_id', $managedInvoice->getKey())
                    ->where('environment', $managedIssuance->environment->value)
                    ->max('attempt_number')) + 1;

                return KsefInvoiceSubmission::query()->create([
                    'invoice_id' => $managedIssuance->invoice_id,
                    'offline_issuance_id' => $managedIssuance->getKey(),
                    'environment' => $managedIssuance->environment,
                    'context_nip' => $managedIssuance->context_identifier_value,
                    'seller_nip' => $managedIssuance->seller_nip,
                    'attempt_number' => $attemptNumber,
                    'status' => KsefInvoiceSubmissionStatus::Preparing,
                    'schema_id' => $managedIssuance->schema_id,
                    'generated_at' => $managedIssuance->issued_at,
                    'payload_xml' => $managedIssuance->payload_xml,
                    'invoice_hash' => $managedIssuance->invoice_hash,
                    'invoice_size' => $managedIssuance->invoice_size,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $latest = KsefInvoiceSubmission::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('environment', $issuance->environment->value)
                ->latest('attempt_number')
                ->first();

            if ($latest !== null && $latest->status !== KsefInvoiceSubmissionStatus::TechnicalFailed) {
                throw $this->attemptBlocked();
            }

            throw $exception;
        }
    }

    private function assertCurrentConfiguration(KsefOfflineIssuance $issuance): void
    {
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();

        if ($settings === null || ! $settings->is_active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_submission_configuration_inactive',
            );
        }

        if (! is_string($settings->context_nip)
            || ! hash_equals((string) $issuance->context_identifier_value, $settings->context_nip)) {
            throw new KsefApiException(
                'Aby przekazać tę historyczną Fakturę Offline, aktywny kontekst NIP KSeF musi odpowiadać kontekstowi zamrożonemu przy wystawieniu.',
                'ksef_offline_submission_context_not_current',
            );
        }
    }

    private function assertNewAttemptAllowed($history, KsefOfflineIssuance $issuance): void
    {
        if ($history->isEmpty()) {
            return;
        }

        if ($history->contains(
            fn (KsefInvoiceSubmission $submission): bool => $submission->offline_issuance_id !== $issuance->getKey(),
        )) {
            throw $this->attemptBlocked();
        }

        if ($history->contains(
            fn (KsefInvoiceSubmission $submission): bool => $submission->status === KsefInvoiceSubmissionStatus::Uncertain,
        )) {
            throw new KsefApiException(
                'Najpierw ustal wynik poprzedniej transmisji Offline. Dokument nie został wysłany ponownie.',
                'ksef_offline_submission_reconciliation_required',
            );
        }

        if ($history->contains(
            fn (KsefInvoiceSubmission $submission): bool => $submission->status === KsefInvoiceSubmissionStatus::Rejected,
        )) {
            throw new KsefApiException(
                'Odrzuconej Faktury Offline nie można wysłać ponownie bez wyjaśnienia przyczyny odrzucenia.',
                'ksef_offline_submission_rejected_retry_blocked',
            );
        }

        if ($history->every(
            fn (KsefInvoiceSubmission $submission): bool => $submission->status === KsefInvoiceSubmissionStatus::TechnicalFailed,
        )) {
            return;
        }

        throw $this->attemptBlocked();
    }

    private function attemptBlocked(): KsefApiException
    {
        return new KsefApiException(
            'Istniejąca próba transmisji Offline blokuje utworzenie kolejnej próby.',
            'ksef_offline_submission_attempt_blocked',
        );
    }

    private function assertTransportEnabled(): void
    {
        if (config('ksef.invoice_submission_enabled') !== true) {
            throw new KsefApiException(
                'Transport Faktur do KSeF jest wyłączony w konfiguracji wdrożenia.',
                'ksef_submission_disabled',
            );
        }
    }
}
