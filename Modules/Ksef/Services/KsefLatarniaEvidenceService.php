<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\ValueObjects\KsefLatarniaEvidenceSnapshot;

final class KsefLatarniaEvidenceService
{
    public function snapshot(
        KsefOfflineIssuance $issuance,
        ?KsefLatarniaSyncState $state,
        CarbonImmutable $asOf,
    ): KsefLatarniaEvidenceSnapshot {
        $requestedAsOf = $asOf->utc();
        $latarniaEnvironment = $this->latarniaEnvironment($issuance->environment);

        if ($latarniaEnvironment === null) {
            return new KsefLatarniaEvidenceSnapshot(
                $issuance->environment,
                null,
                KsefLatarniaEvidenceCoverage::UnsupportedEnvironment,
                null,
                null,
                $requestedAsOf,
            );
        }

        $coverageFrom = $state?->messages_coverage_from_at;
        $coverageThrough = $state?->messages_coverage_through_at;
        $freshnessMinutes = max(1, (int) config('ksef.latarnia.freshness_minutes', 15));
        $complete = $state !== null
            && $state->source_environment === $latarniaEnvironment
            && $coverageFrom !== null
            && $coverageThrough !== null
            && ! $coverageFrom->greaterThan($coverageThrough)
            && ! $issuance->issued_at->lessThan($coverageFrom)
            && ! $issuance->issued_at->greaterThan($coverageThrough)
            && ! $coverageThrough->greaterThan($requestedAsOf)
            && ! $coverageThrough->lessThan($requestedAsOf->subMinutes($freshnessMinutes));

        return new KsefLatarniaEvidenceSnapshot(
            $issuance->environment,
            $latarniaEnvironment,
            $complete
                ? KsefLatarniaEvidenceCoverage::Complete
                : KsefLatarniaEvidenceCoverage::Insufficient,
            $coverageFrom,
            $coverageThrough,
            $complete ? $coverageThrough : $requestedAsOf,
        );
    }

    private function latarniaEnvironment(KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };
    }
}
