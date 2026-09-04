<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Models\KsefLatarniaSyncState;

final class KsefLatarniaStatusPresenter
{
    /** @return array<string, mixed> */
    public function present(
        KsefEnvironment $environment,
        ?KsefLatarniaSyncState $state,
        CarbonImmutable $asOf,
    ): array {
        $latarniaEnvironment = $this->latarniaEnvironment($environment);

        if ($latarniaEnvironment === null) {
            return [
                'supported' => false,
                'environment' => 'DEMO',
                'latarnia_environment' => null,
                'label' => 'Latarnia niedostępna dla środowiska DEMO.',
                'variant' => 'secondary',
                'fresh' => false,
                'state' => null,
            ];
        }

        $now = $asOf->utc();
        $freshnessMinutes = max(1, (int) config('ksef.latarnia.freshness_minutes', 15));
        $lastSuccess = $state?->status_last_success_at;
        $fresh = $lastSuccess !== null
            && ! $lastSuccess->greaterThan($now)
            && ! $lastSuccess->lessThan($now->subMinutes($freshnessMinutes));
        $coverageThrough = $state?->messages_coverage_through_at;
        $coverageFresh = $coverageThrough !== null
            && ! $coverageThrough->greaterThan($now)
            && ! $coverageThrough->lessThan($now->subMinutes($freshnessMinutes));
        [$label, $variant] = $fresh && $state?->current_status !== null
            ? $this->status($state->current_status)
            : ['Brak aktualnych danych Latarni', 'warning'];

        return [
            'supported' => true,
            'environment' => strtoupper($environment->value),
            'latarnia_environment' => strtoupper($latarniaEnvironment->value),
            'label' => $label,
            'variant' => $variant,
            'fresh' => $fresh,
            'coverage_fresh' => $coverageFresh,
            'state' => $state,
        ];
    }

    private function latarniaEnvironment(KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };
    }

    /** @return array{0: string, 1: string} */
    private function status(KsefLatarniaStatus $status): array
    {
        return match ($status) {
            KsefLatarniaStatus::Available => ['KSeF dostępny', 'success'],
            KsefLatarniaStatus::Maintenance => ['Prace serwisowe KSeF', 'warning'],
            KsefLatarniaStatus::Failure => ['Awaria KSeF', 'danger'],
            KsefLatarniaStatus::TotalFailure => ['Awaria całkowita KSeF', 'danger'],
        };
    }
}
