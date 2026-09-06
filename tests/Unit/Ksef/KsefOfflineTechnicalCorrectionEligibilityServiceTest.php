<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionEligibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KsefOfflineTechnicalCorrectionEligibilityServiceTest extends TestCase
{
    #[DataProvider('statusCodes')]
    public function test_only_the_narrow_machine_readable_allowlist_is_eligible(
        ?int $statusCode,
        KsefTechnicalCorrectionEligibility $expected,
    ): void {
        $result = (new KsefOfflineTechnicalCorrectionEligibilityService)->classify($statusCode);

        $this->assertSame($expected, $result);
    }

    public static function statusCodes(): array
    {
        return [
            'duplicate invoice 440' => [440, KsefTechnicalCorrectionEligibility::Eligible],
            'semantic validation 450' => [450, KsefTechnicalCorrectionEligibility::Eligible],
            'invalid permissions 410' => [410, KsefTechnicalCorrectionEligibility::Ineligible],
            '405 unknown' => [405, KsefTechnicalCorrectionEligibility::Unknown],
            '415 unknown' => [415, KsefTechnicalCorrectionEligibility::Unknown],
            '430 unknown' => [430, KsefTechnicalCorrectionEligibility::Unknown],
            '435 unknown' => [435, KsefTechnicalCorrectionEligibility::Unknown],
            '500 unknown despite any textual description elsewhere' => [500, KsefTechnicalCorrectionEligibility::Unknown],
            '550 unknown' => [550, KsefTechnicalCorrectionEligibility::Unknown],
            'missing code unknown' => [null, KsefTechnicalCorrectionEligibility::Unknown],
        ];
    }
}
