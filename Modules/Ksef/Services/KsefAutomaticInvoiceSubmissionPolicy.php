<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

class KsefAutomaticInvoiceSubmissionPolicy
{
    public function __construct(
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    /** @return array{environment: KsefEnvironment, context_nip: string}|null */
    public function snapshotFor(Invoice $invoice): ?array
    {
        if (config('ksef.invoice_submission_enabled') !== true
            || ! $invoice->isInvoice()
            || ! $invoice->isIssued()) {
            return null;
        }

        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();

        if ($settings === null
            || ! $settings->is_active
            || ! $settings->automatic_submission
            || ! $this->environments->allows($settings->environment)) {
            return null;
        }

        $contextNip = trim((string) $settings->context_nip);
        if (preg_match('/^\d{10}$/', $contextNip) !== 1) {
            return null;
        }

        if (! KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->where('is_enabled', true)
            ->exists()) {
            return null;
        }

        if (KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $settings->environment->value)
            ->exists()) {
            return null;
        }

        return [
            'environment' => $settings->environment,
            'context_nip' => $contextNip,
        ];
    }

    public function allows(
        Invoice $invoice,
        KsefEnvironment $expectedEnvironment,
        string $expectedContextNip,
    ): bool {
        $snapshot = $this->snapshotFor($invoice);

        return $snapshot !== null
            && $snapshot['environment'] === $expectedEnvironment
            && $snapshot['context_nip'] === $expectedContextNip;
    }
}
