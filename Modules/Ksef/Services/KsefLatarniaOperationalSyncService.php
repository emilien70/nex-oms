<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefLatarniaSyncResult;
use Throwable;

final class KsefLatarniaOperationalSyncService
{
    public function __construct(
        private readonly KsefLatarniaSyncService $sync,
    ) {}

    /** @return array<string, KsefLatarniaSyncResult|null> */
    public function runScheduled(): array
    {
        if (config('ksef.latarnia.sync_enabled') !== true) {
            return [];
        }

        $results = [];

        foreach ($this->relevantEnvironments() as $environment) {
            try {
                $results[$environment->value] = $this->sync->sync($environment);
            } catch (Throwable $exception) {
                Log::warning('Nieoczekiwany błąd synchronizacji Latarni KSeF.', [
                    'environment' => $environment->value,
                    'exception' => $exception::class,
                ]);
                $results[$environment->value] = null;
            }
        }

        return $results;
    }

    /** @return list<KsefLatarniaEnvironment> */
    public function relevantEnvironments(): array
    {
        $environmentValues = collect();
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->where('is_active', true)
            ->first(['environment']);

        if ($settings !== null) {
            $environmentValues->push($settings->environment->value);
        }

        $environmentValues = $environmentValues->merge(
            DB::table((new KsefOfflineIssuance)->getTable())
                ->distinct()
                ->pluck('environment'),
        );

        return $environmentValues
            ->map(fn (mixed $value): ?KsefLatarniaEnvironment => is_string($value)
                ? $this->latarniaEnvironment(KsefEnvironment::tryFrom($value))
                : null)
            ->filter()
            ->unique(fn (KsefLatarniaEnvironment $environment): string => $environment->value)
            ->sortBy(fn (KsefLatarniaEnvironment $environment): int => $environment === KsefLatarniaEnvironment::Test ? 0 : 1)
            ->values()
            ->all();
    }

    private function latarniaEnvironment(?KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            default => null,
        };
    }
}
