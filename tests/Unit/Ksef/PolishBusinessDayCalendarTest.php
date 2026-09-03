<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Ksef\Services\PolishBusinessDayCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolishBusinessDayCalendarTest extends TestCase
{
    #[DataProvider('holidayCases')]
    public function test_polish_statutory_and_movable_holidays_are_not_business_days(string $date): void
    {
        $this->assertFalse($this->calendar()->isBusinessDay($this->date($date)));
    }

    public static function holidayCases(): array
    {
        return [
            'new year' => ['2026-01-01'],
            'epiphany' => ['2026-01-06'],
            'easter 2026' => ['2026-04-05'],
            'easter monday 2026' => ['2026-04-06'],
            'labour day' => ['2026-05-01'],
            'constitution day' => ['2026-05-03'],
            'pentecost 2026' => ['2026-05-24'],
            'corpus christi 2026' => ['2026-06-04'],
            'assumption' => ['2026-08-15'],
            'all saints' => ['2026-11-01'],
            'independence day' => ['2026-11-11'],
            'christmas eve since 2025' => ['2026-12-24'],
            'christmas day' => ['2026-12-25'],
            'second christmas day' => ['2026-12-26'],
            'easter 2027' => ['2027-03-28'],
            'easter monday 2027' => ['2027-03-29'],
            'pentecost 2027' => ['2027-05-16'],
            'corpus christi 2027' => ['2027-05-27'],
            'easter 2030' => ['2030-04-21'],
            'easter monday 2030' => ['2030-04-22'],
            'pentecost 2030' => ['2030-06-09'],
            'corpus christi 2030' => ['2030-06-20'],
        ];
    }

    public function test_saturday_sunday_and_holiday_sequence_are_skipped(): void
    {
        $calendar = $this->calendar();

        $this->assertFalse($calendar->isBusinessDay($this->date('2026-09-05')));
        $this->assertFalse($calendar->isBusinessDay($this->date('2026-09-06')));
        $this->assertTrue($calendar->isBusinessDay($this->date('2026-09-07')));
        $this->assertSame(
            '2026-12-28',
            $calendar->nextBusinessDayAfter($this->date('2026-12-23'))->toDateString(),
        );
    }

    public function test_seven_business_days_exclude_trigger_day_weekends_and_holidays(): void
    {
        $this->assertSame(
            '2026-03-17',
            $this->calendar()->addBusinessDaysAfter($this->date('2026-03-08'), 7)->toDateString(),
        );
        $this->assertSame(
            '2027-01-07',
            $this->calendar()->addBusinessDaysAfter($this->date('2026-12-23'), 7)->toDateString(),
        );
    }

    public function test_leap_day_and_dates_before_christmas_eve_reform_are_deterministic(): void
    {
        $calendar = $this->calendar();

        $this->assertTrue($calendar->isBusinessDay($this->date('2028-02-29')));
        $this->assertTrue($calendar->isBusinessDay($this->date('2024-12-24')));
        $this->assertSame('Europe/Warsaw', $calendar->nextBusinessDayAfter(
            CarbonImmutable::parse('2026-09-04T23:30:00Z'),
        )->timezoneName);
    }

    public function test_business_day_count_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calendar()->addBusinessDaysAfter($this->date('2026-09-01'), 0);
    }

    private function calendar(): PolishBusinessDayCalendar
    {
        return app(PolishBusinessDayCalendar::class);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Warsaw');
    }
}
