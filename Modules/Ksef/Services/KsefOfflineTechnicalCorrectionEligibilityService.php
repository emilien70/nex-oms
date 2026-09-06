<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility;

final class KsefOfflineTechnicalCorrectionEligibilityService
{
    public function classify(?int $statusCode): KsefTechnicalCorrectionEligibility
    {
        return match ($statusCode) {
            440, 450 => KsefTechnicalCorrectionEligibility::Eligible,
            410 => KsefTechnicalCorrectionEligibility::Ineligible,
            default => KsefTechnicalCorrectionEligibility::Unknown,
        };
    }
}
