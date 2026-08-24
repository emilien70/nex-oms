<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;

class KsefMonthlyExportPeriod
{
    private const PREVIOUS_MONTHS = 12;

    /** @return array<string, string> */
    public function options(): array
    {
        $current = CarbonImmutable::now((string) config('app.timezone', 'UTC'))
            ->startOfMonth();
        $options = [];

        for ($offset = 0; $offset <= self::PREVIOUS_MONTHS; $offset++) {
            $month = $current->subMonthsNoOverflow($offset);
            $options[$month->format('Y-m')] = $month->format('m.Y');
        }

        return $options;
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_keys($this->options());
    }

    public function allows(string $month): bool
    {
        return in_array($month, $this->values(), true);
    }

    /** @return array{0: string, 1: string} */
    public function dateBounds(string $month): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!Y-m',
            $month,
            (string) config('app.timezone', 'UTC'),
        );

        return [
            $start->startOfMonth()->toDateString(),
            $start->endOfMonth()->toDateString(),
        ];
    }

    public function label(string $month): string
    {
        return $this->options()[$month] ?? $month;
    }
}
