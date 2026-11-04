<?php

namespace Modules\Shipments\Support;

use Carbon\CarbonImmutable;

final class ShipmentConfirmationSchedule
{
    public const TIMEOUT_SECONDS = 900;

    public static function delay(int $startedAt, ?int $retryAfter = null): int
    {
        $elapsed = max(0, CarbonImmutable::now()->getTimestamp() - $startedAt);
        $interval = match (true) {
            $elapsed < 10 => 1,
            $elapsed < 30 => 2,
            $elapsed < 60 => 5,
            default => 30,
        };

        return max($interval, $retryAfter ?? 0);
    }

    public static function retryAfter(?string $header): ?int
    {
        $header = trim($header ?? '');
        if (preg_match('/^[0-9]{1,9}$/D', $header)) {
            return max(1, (int) $header);
        }

        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $header, new \DateTimeZone('GMT'));
        if (! $date || $date->format(DATE_RFC7231) !== $header) {
            return null;
        }

        return max(1, $date->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}
