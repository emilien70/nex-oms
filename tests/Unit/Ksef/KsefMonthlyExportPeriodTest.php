<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Services\KsefMonthlyExportPeriod;
use Tests\TestCase;

class KsefMonthlyExportPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_current_and_twelve_previous_months_share_one_ordered_contract(): void
    {
        $periods = new KsefMonthlyExportPeriod;

        $this->assertCount(13, $periods->options());
        $this->assertSame('08.2026', $periods->options()['2026-08']);
        $this->assertSame('08.2025', $periods->options()['2025-08']);
        $this->assertSame('2026-08', array_key_first($periods->options()));
        $this->assertSame('2025-08', array_key_last($periods->options()));
        $this->assertSame(['2026-08-01', '2026-08-31'], $periods->dateBounds('2026-08'));
        $this->assertTrue($periods->allows('2026-08'));
        $this->assertTrue($periods->allows('2025-08'));
        $this->assertFalse($periods->allows('2025-07'));
    }
}
