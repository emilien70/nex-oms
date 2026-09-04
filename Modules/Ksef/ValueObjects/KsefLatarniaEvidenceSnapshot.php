<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;

final readonly class KsefLatarniaEvidenceSnapshot
{
    public function __construct(
        public KsefEnvironment $environment,
        public ?KsefLatarniaEnvironment $latarniaEnvironment,
        public KsefLatarniaEvidenceCoverage $coverage,
        public ?CarbonImmutable $coverageFrom,
        public ?CarbonImmutable $coverageThrough,
        public CarbonImmutable $evaluationAsOf,
    ) {}
}
