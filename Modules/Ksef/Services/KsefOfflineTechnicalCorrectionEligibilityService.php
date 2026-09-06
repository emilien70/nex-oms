<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility;

class KsefOfflineTechnicalCorrectionEligibilityService
{
    public const CURRENT_POLICY_VERSION = 1;

    public function classify(?int $statusCode): KsefTechnicalCorrectionEligibility
    {
        return $this->classifyForVersion($statusCode, self::CURRENT_POLICY_VERSION);
    }

    final public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    final public function classifyForVersion(
        ?int $statusCode,
        int $version,
    ): KsefTechnicalCorrectionEligibility {
        return match ($version) {
            1 => match ($statusCode) {
                440, 450 => KsefTechnicalCorrectionEligibility::Eligible,
                410 => KsefTechnicalCorrectionEligibility::Ineligible,
                default => KsefTechnicalCorrectionEligibility::Unknown,
            },
            default => KsefTechnicalCorrectionEligibility::Unknown,
        };
    }
}
