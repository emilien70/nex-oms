<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Shipments\Support\ShipmentConfirmationSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentConfirmationScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('intervals')]
    public function test_confirmation_slows_down_without_shortening_retry_after(int $elapsed, ?int $retryAfter, int $expected): void
    {
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        $start = CarbonImmutable::now()->getTimestamp() - $elapsed;
        $this->assertSame($expected, ShipmentConfirmationSchedule::delay($start, $retryAfter));
    }

    public static function intervals(): array
    {
        return [[0, null, 1], [9, null, 1], [10, null, 2], [30, null, 5],
            [60, null, 30], [120, null, 30], [0, 7, 7], [120, 90, 90], [30, 1, 5]];
    }

    #[DataProvider('headers')]
    public function test_retry_after_accepts_only_seconds_or_an_http_date(?string $header, ?int $expected): void
    {
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        $this->assertSame($expected, ShipmentConfirmationSchedule::retryAfter($header));
    }

    public static function headers(): array
    {
        return [[null, null], ['', null], ['7', 7], [' 60 ', 60], ['0', 1], ['-1', null],
            ['1.5', null], ['tomorrow', null], ['999999999999999', null],
            ['Tue, 03 Nov 2026 12:00:07 GMT', 7], ['Tue, 03 Nov 2026 11:59:00 GMT', 1],
            ['Tue, 99 Nov 2026 12:00:00 GMT', null]];
    }
}
