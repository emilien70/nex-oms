<?php

namespace Tests\Unit\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Support\StatisticsPeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatisticsPeriodResolverTest extends TestCase
{
    #[DataProvider('presetProvider')]
    public function test_it_resolves_period_presets(string $preset, string $from, string $to, int $days): void
    {
        $period = app(StatisticsPeriodResolver::class)->resolve(
            $preset,
            today: CarbonImmutable::parse('2026-10-19', 'Europe/Warsaw'),
        );

        $this->assertSame($from, $period['from']->toDateString());
        $this->assertSame($to, $period['to']->toDateString());
        $this->assertSame($days, $period['days']);
    }

    public static function presetProvider(): array
    {
        return [
            'today' => ['today', '2026-10-19', '2026-10-19', 1],
            'last seven days' => ['last_7_days', '2026-10-13', '2026-10-19', 7],
            'previous month' => ['previous_month', '2026-09-01', '2026-09-30', 30],
            'current quarter' => ['current_quarter', '2026-10-01', '2026-10-19', 19],
            'previous year' => ['previous_year', '2025-01-01', '2025-12-31', 365],
        ];
    }

    public function test_it_resolves_and_shifts_a_custom_period(): void
    {
        $resolver = app(StatisticsPeriodResolver::class);
        $today = CarbonImmutable::parse('2026-10-19', 'Europe/Warsaw');
        $period = $resolver->resolve('custom', '2026-10-01', '2026-10-10', $today);

        $this->assertSame(['from' => '2026-09-21', 'to' => '2026-09-30'], $resolver->shifted($period, -1, $today));
        $this->assertSame(['from' => '2026-10-10', 'to' => '2026-10-19'], $resolver->shifted($period, 1, $today));
    }

    public function test_it_rejects_an_invalid_custom_period(): void
    {
        $this->expectException(ValidationException::class);

        app(StatisticsPeriodResolver::class)->resolve(
            'custom',
            '2026-10-20',
            '2026-10-21',
            CarbonImmutable::parse('2026-10-19', 'Europe/Warsaw'),
        );
    }
}
