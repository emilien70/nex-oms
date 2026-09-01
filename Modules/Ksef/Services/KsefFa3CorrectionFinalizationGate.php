<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

final class KsefFa3CorrectionFinalizationGate
{
    public function __construct(
        private readonly KsefFa3CorrectionEligibilityValidator $eligibility,
    ) {}

    public function assertCorrectionCanFinalize(Invoice $correction): void
    {
        if (! $correction->isCorrection()) {
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
            ->where('invoice_series_id', $correction->invoice_series_id)
            ->where('is_enabled', true)
            ->lockForUpdate()
            ->exists();

        if (! $seriesEnabled) {
            return;
        }

        $this->eligibility->assertEligible(
            $correction,
            $settings,
            KsefFa3EligibilityMode::Preflight,
        );
    }
}
