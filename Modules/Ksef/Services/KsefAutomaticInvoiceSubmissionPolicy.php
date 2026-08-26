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

    public function environmentFor(Invoice $invoice): ?KsefEnvironment
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

        return $settings->environment;
    }

    public function allows(Invoice $invoice, KsefEnvironment $expectedEnvironment): bool
    {
        return $this->environmentFor($invoice) === $expectedEnvironment;
    }
}
