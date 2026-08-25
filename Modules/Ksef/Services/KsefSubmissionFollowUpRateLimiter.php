<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\RateLimiter;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefSubmissionFollowUpRateLimiter
{
    private const WINDOWS = [
        'per_second' => 1,
        'per_minute' => 60,
        'per_hour' => 3600,
    ];

    public function reserve(
        string $operation,
        KsefEnvironment $environment,
        string $contextNip,
    ): ?int {
        $limits = config("ksef.follow_up.rate_limits.{$operation}", []);
        $keys = [];
        $wait = 0;

        foreach (self::WINDOWS as $window => $decaySeconds) {
            $limit = max(1, (int) ($limits[$window] ?? 1));
            $key = $this->key($operation, $environment, $contextNip, $window);
            $keys[] = [$key, $decaySeconds];

            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            return max(1, $wait);
        }

        foreach ($keys as [$key, $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return null;
    }

    private function key(
        string $operation,
        KsefEnvironment $environment,
        string $contextNip,
        string $window,
    ): string {
        return implode(':', [
            'ksef-follow-up',
            $operation,
            $environment->value,
            $contextNip,
            $window,
        ]);
    }
}
