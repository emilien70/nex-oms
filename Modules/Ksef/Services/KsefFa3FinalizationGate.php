<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

class KsefFa3FinalizationGate
{
    public function __construct(
        private readonly KsefFa3EligibilityValidator $eligibility,
    ) {}

    public function assertInvoiceCanFinalize(Invoice $invoice): void
    {
        if (! $invoice->isInvoice()) {
            return;
        }

        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();

        if ($settings === null || ! $settings->is_active) {
            return;
        }

        $seriesEnabled = KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->where('is_enabled', true)
            ->lockForUpdate()
            ->exists();

        if (! $seriesEnabled) {
            return;
        }

        $this->eligibility->assertEligible(
            $invoice,
            $settings,
            KsefFa3EligibilityMode::Preflight,
        );
    }
}
