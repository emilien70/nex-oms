<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;

final class PolishBusinessDayCalendar
{
    private const TIMEZONE = 'Europe/Warsaw';

    public function isBusinessDay(CarbonImmutable $date): bool
    {
        $localDate = $this->date($date);

        return ! $localDate->isWeekend()
            && ! in_array($localDate->toDateString(), $this->holidays($localDate->year), true);
    }

    public function nextBusinessDayAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->addBusinessDaysAfter($date, 1);
    }

    public function addBusinessDaysAfter(CarbonImmutable $date, int $count): CarbonImmutable
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Business day count must be positive.');
        }

        $candidate = $this->date($date);
        $remaining = $count;

        while ($remaining > 0) {
            $candidate = $candidate->addDay();

            if ($this->isBusinessDay($candidate)) {
                $remaining--;
            }
        }

        return $candidate;
    }

    /** @return list<string> */
    private function holidays(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-06', $year),
            $easter->toDateString(),
            $easter->addDay()->toDateString(),
            sprintf('%04d-05-01', $year),
            sprintf('%04d-05-03', $year),
            $easter->addDays(49)->toDateString(),
            $easter->addDays(60)->toDateString(),
            sprintf('%04d-08-15', $year),
            sprintf('%04d-11-01', $year),
            sprintf('%04d-11-11', $year),
            ...($year >= 2025 ? [sprintf('%04d-12-24', $year)] : []),
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ];
    }

    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);
    }

    private function date(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(self::TIMEZONE)->startOfDay();
    }
}
