<?php

namespace Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StatisticsPeriodResolver
{
    public const DEFAULT_RANGE = 'last_30_days';

    /** @return array<string, string> */
    public function options(CarbonImmutable $today): array
    {
        return [
            'today' => 'Dzisiaj',
            'since_yesterday' => 'Od wczoraj',
            'last_7_days' => 'Ostatnie 7 dni',
            'last_14_days' => 'Ostatnie 14 dni',
            'last_28_days' => 'Ostatnie 4 tygodnie (28 dni)',
            'last_30_days' => 'Ostatnie 30 dni',
            'current_month' => 'Bieżący miesiąc',
            'previous_month' => 'Poprzedni miesiąc',
            'current_quarter' => 'Bieżący kwartał',
            'previous_quarter' => 'Poprzedni kwartał',
            'current_year' => 'Bieżący rok',
            'previous_year' => 'Poprzedni rok',
            'custom' => 'Niestandardowy',
        ];
    }

    /**
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, label: string, days: int}
     */
    public function resolve(
        ?string $range,
        ?string $customFrom = null,
        ?string $customTo = null,
        ?CarbonImmutable $today = null,
    ): array {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        $key = array_key_exists((string) $range, $this->options($today))
            ? (string) $range
            : self::DEFAULT_RANGE;

        [$from, $to] = match ($key) {
            'today' => [$today, $today],
            'since_yesterday' => [$today->subDay(), $today],
            'last_7_days' => [$today->subDays(6), $today],
            'last_14_days' => [$today->subDays(13), $today],
            'last_28_days' => [$today->subDays(27), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'current_month' => [$today->startOfMonth(), $today],
            'previous_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'current_quarter' => [$today->startOfQuarter(), $today],
            'previous_quarter' => [$today->subQuarter()->startOfQuarter(), $today->subQuarter()->endOfQuarter()],
            'current_year' => [$today->startOfYear(), $today],
            'previous_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'custom' => $this->customPeriod($customFrom, $customTo, $today),
            default => [$today->subDays(29), $today],
        };

        return $this->period($key, $from, $to);
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $period
     * @return array{from: string, to: string}
     */
    public function shifted(array $period, int $direction, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        $days = (int) $period['from']->diffInDays($period['to']) + 1;
        $from = $direction < 0
            ? $period['from']->subDays($days)
            : $period['from']->addDays($days);
        $to = $direction < 0
            ? $period['to']->subDays($days)
            : $period['to']->addDays($days);

        if ($to->isAfter($today)) {
            $to = $today;
            $from = $today->subDays($days - 1);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    public function formatDate(CarbonImmutable $date): string
    {
        $months = [
            1 => 'Sty', 2 => 'Lut', 3 => 'Mar', 4 => 'Kwi',
            5 => 'Maj', 6 => 'Cze', 7 => 'Lip', 8 => 'Sie',
            9 => 'Wrz', 10 => 'Paź', 11 => 'Lis', 12 => 'Gru',
        ];

        return $date->day.' '.$months[$date->month].' '.$date->year;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function customPeriod(?string $from, ?string $to, CarbonImmutable $today): array
    {
        try {
            $resolvedFrom = CarbonImmutable::createFromFormat('!Y-m-d', (string) $from, config('app.timezone'));
            $resolvedTo = CarbonImmutable::createFromFormat('!Y-m-d', (string) $to, config('app.timezone'));
        } catch (\Throwable) {
            $resolvedFrom = null;
            $resolvedTo = null;
        }

        if (! $resolvedFrom || ! $resolvedTo || $resolvedFrom->isAfter($resolvedTo) || $resolvedTo->isAfter($today)) {
            throw ValidationException::withMessages([
                'date_from' => 'Wybierz prawidłowy zakres dat, który nie kończy się w przyszłości.',
            ]);
        }

        return [$resolvedFrom, $resolvedTo];
    }

    /** @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, label: string, days: int} */
    private function period(string $key, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'label' => $this->formatDate($from).' - '.$this->formatDate($to),
            'days' => (int) $from->diffInDays($to) + 1,
        ];
    }
}
