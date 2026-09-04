<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Services\KsefLatarniaMaintenanceEventProjector;
use Tests\TestCase;

class KsefLatarniaMaintenanceEventProjectorTest extends TestCase
{
    public function test_latest_message_version_defines_one_maintenance_event(): void
    {
        $projection = $this->project([
            $this->message(['version' => 1, 'end_at' => '2026-09-05T11:00:00Z']),
            $this->message(['version' => 2, 'end_at' => '2026-09-05T12:00:00Z']),
        ]);

        $this->assertFalse($projection->isAmbiguous());
        $this->assertCount(1, $projection->events);
        $this->assertSame(2, $projection->events[0]->messageVersion);
        $this->assertSame('2026-09-05T12:00:00+00:00', $projection->events[0]->endAt->toIso8601String());
    }

    public function test_multiple_announcements_for_one_event_fail_closed(): void
    {
        $projection = $this->project([
            $this->message(),
            $this->message(['external_message_id' => 'MAINTENANCE-2']),
        ]);

        $this->assertTrue($projection->isAmbiguous());
        $this->assertSame([501], $projection->ambiguousEventIds);
        $this->assertEqualsCanonicalizing(
            ['MAINTENANCE-1', 'MAINTENANCE-2'],
            $projection->ambiguousMessageIds,
        );
    }

    public function test_other_environment_and_invalid_maintenance_message_are_not_accepted(): void
    {
        $projection = $this->project([
            $this->message(['source_environment' => KsefLatarniaEnvironment::Production]),
            $this->message(['external_message_id' => 'WRONG-TYPE', 'type' => 'FAILURE_START']),
        ]);

        $this->assertTrue($projection->isAmbiguous());
        $this->assertSame([], $projection->events);
        $this->assertSame(['WRONG-TYPE'], $projection->ambiguousMessageIds);
    }

    private function project(array $messages)
    {
        return app(KsefLatarniaMaintenanceEventProjector::class)->project(
            $messages,
            KsefLatarniaEnvironment::Test,
        );
    }

    private function message(array $overrides = []): KsefLatarniaMessage
    {
        $attributes = array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'MAINTENANCE-1',
            'event_id' => 501,
            'version' => 1,
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Synthetic maintenance',
            'text' => 'Synthetic maintenance fixture.',
            'start_at' => '2026-09-05T10:00:00Z',
            'end_at' => '2026-09-05T11:00:00Z',
            'published_at' => '2026-09-05T09:00:00Z',
            'payload_json' => '{}',
            'payload_hash' => str_repeat('A', 44),
            'first_fetched_at' => '2026-09-05T09:01:00Z',
            'last_seen_at' => '2026-09-05T09:01:00Z',
        ], $overrides);

        foreach (['start_at', 'end_at', 'published_at', 'first_fetched_at', 'last_seen_at'] as $field) {
            if (is_string($attributes[$field])) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field]);
            }
        }

        return new KsefLatarniaMessage($attributes);
    }
}
