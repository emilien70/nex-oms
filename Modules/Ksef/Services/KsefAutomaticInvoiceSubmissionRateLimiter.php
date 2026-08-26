<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefAutomaticInvoiceSubmissionRateLimiter
{
    private const WINDOWS = [
        'per_second' => 1,
        'per_minute' => 60,
        'per_hour' => 3600,
    ];

    public function reserveDelay(
        KsefEnvironment $environment,
        string $contextNip,
    ): int {
        $spacing = $this->minimumSpacingSeconds();
        $key = $this->key($environment, $contextNip);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $spacing): int {
            $now = now()->timestamp;
            $slot = max($now, (int) Cache::get($key, $now));
            $delay = max(0, $slot - $now);

            Cache::put(
                $key,
                $slot + $spacing,
                $delay + $spacing + 3600,
            );

            return $delay;
        });
    }

    private function minimumSpacingSeconds(): int
    {
        $limits = config('ksef.automatic_submission.rate_limits', []);

        return collect(self::WINDOWS)
            ->map(function (int $windowSeconds, string $window) use ($limits): int {
                $limit = max(1, (int) ($limits[$window] ?? 1));

                return (int) ceil($windowSeconds / $limit);
            })
            ->max();
    }

    private function key(KsefEnvironment $environment, string $contextNip): string
    {
        return implode(':', [
            'ksef-automatic-submission-slot',
            $environment->value,
            hash('sha256', $contextNip),
        ]);
    }
}
