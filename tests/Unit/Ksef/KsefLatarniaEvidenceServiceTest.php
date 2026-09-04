<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefLatarniaEvidenceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefLatarniaEvidenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('ksef.latarnia.freshness_minutes', 15);
    }

    public function test_demo_is_explicitly_unsupported_without_state(): void
    {
        $snapshot = $this->snapshot(
            $this->issuance(KsefEnvironment::Demo, '2026-09-04T09:00:00Z'),
            null,
            '2026-09-04T10:00:00Z',
        );

        $this->assertSame(KsefLatarniaEvidenceCoverage::UnsupportedEnvironment, $snapshot->coverage);
        $this->assertNull($snapshot->latarniaEnvironment);
        $this->assertSame('2026-09-04 10:00:00', $snapshot->evaluationAsOf->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_fresh_complete_coverage_evaluates_exactly_at_coverage_through(): void
    {
        $state = $this->state('2026-08-05T10:00:00Z', '2026-09-04T10:00:00Z');
        $snapshot = $this->snapshot(
            $this->issuance(KsefEnvironment::Test, '2026-08-05T10:00:00Z'),
            $state,
            '2026-09-04T10:04:00Z',
        );

        $this->assertSame(KsefLatarniaEvidenceCoverage::Complete, $snapshot->coverage);
        $this->assertSame(KsefLatarniaEnvironment::Test, $snapshot->latarniaEnvironment);
        $this->assertSame('2026-09-04 10:00:00', $snapshot->evaluationAsOf->format('Y-m-d H:i:s'));
    }

    #[DataProvider('insufficientCoverageProvider')]
    public function test_incomplete_stale_or_corrupt_coverage_fails_closed(
        ?string $from,
        ?string $through,
        string $issuedAt,
        string $asOf,
    ): void {
        $snapshot = $this->snapshot(
            $this->issuance(KsefEnvironment::Test, $issuedAt),
            $this->state($from, $through),
            $asOf,
        );

        $this->assertSame(KsefLatarniaEvidenceCoverage::Insufficient, $snapshot->coverage);
        $this->assertSame(
            CarbonImmutable::parse($asOf)->utc()->format('Y-m-d H:i:s'),
            $snapshot->evaluationAsOf->format('Y-m-d H:i:s'),
        );
    }

    public static function insufficientCoverageProvider(): array
    {
        return [
            'missing coverage' => [null, null, '2026-09-04T09:00:00Z', '2026-09-04T10:00:00Z'],
            'issuance before coverage' => ['2026-09-04T09:00:01Z', '2026-09-04T10:00:00Z', '2026-09-04T09:00:00Z', '2026-09-04T10:04:00Z'],
            'stale through' => ['2026-08-05T09:00:00Z', '2026-09-04T09:44:59Z', '2026-09-04T09:00:00Z', '2026-09-04T10:00:00Z'],
            'future through' => ['2026-08-05T09:00:00Z', '2026-09-04T10:00:01Z', '2026-09-04T09:00:00Z', '2026-09-04T10:00:00Z'],
            'reversed coverage' => ['2026-09-04T10:00:00Z', '2026-09-04T09:59:59Z', '2026-09-04T09:00:00Z', '2026-09-04T10:00:00Z'],
        ];
    }

    private function snapshot(
        KsefOfflineIssuance $issuance,
        ?KsefLatarniaSyncState $state,
        string $asOf,
    ) {
        return app(KsefLatarniaEvidenceService::class)->snapshot(
            $issuance,
            $state,
            CarbonImmutable::parse($asOf),
        );
    }

    private function issuance(KsefEnvironment $environment, string $issuedAt): KsefOfflineIssuance
    {
        return (new KsefOfflineIssuance)->forceFill([
            'environment' => $environment,
            'issued_at' => CarbonImmutable::parse($issuedAt),
        ]);
    }

    private function state(?string $from, ?string $through): KsefLatarniaSyncState
    {
        return (new KsefLatarniaSyncState)->forceFill([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'messages_coverage_from_at' => $from === null ? null : CarbonImmutable::parse($from),
            'messages_coverage_through_at' => $through === null ? null : CarbonImmutable::parse($through),
        ]);
    }
}
