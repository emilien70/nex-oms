<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Services\KsefLatarniaFailureEventProjector;
use Tests\TestCase;

class KsefLatarniaFailureEventProjectorTest extends TestCase
{
    public function test_latest_message_version_is_used_and_start_can_carry_the_end(): void
    {
        $projection = $this->project([
            $this->message(['version' => 1, 'end_at' => null]),
            $this->message(['version' => 2, 'end_at' => '2026-09-03T12:00:00Z']),
        ]);

        $this->assertFalse($projection->isAmbiguous());
        $this->assertCount(1, $projection->events);
        $this->assertSame('2026-09-03T12:00:00+00:00', $projection->events[0]->endAt?->toIso8601String());
        $this->assertSame(['START-1'], $projection->events[0]->messageIds);
    }

    public function test_matching_start_and_end_messages_form_one_event(): void
    {
        $projection = $this->project([
            $this->message(),
            $this->message([
                'external_message_id' => 'END-1',
                'type' => 'FAILURE_END',
                'end_at' => '2026-09-03T12:00:00Z',
                'published_at' => '2026-09-03T12:01:00Z',
            ]),
        ]);

        $this->assertFalse($projection->isAmbiguous());
        $this->assertSame(['END-1', 'START-1'], $projection->events[0]->messageIds);
        $this->assertSame('2026-09-03 12:00:00', $projection->events[0]->endAt?->format('Y-m-d H:i:s'));
    }

    public function test_matching_end_on_start_and_end_message_is_allowed(): void
    {
        $end = '2026-09-03T12:00:00Z';
        $projection = $this->project([
            $this->message(['end_at' => $end]),
            $this->message([
                'external_message_id' => 'END-1',
                'type' => 'FAILURE_END',
                'end_at' => $end,
                'published_at' => '2026-09-03T12:01:00Z',
            ]),
        ]);

        $this->assertFalse($projection->isAmbiguous());
        $this->assertSame('2026-09-03 12:00:00', $projection->events[0]->endAt?->format('Y-m-d H:i:s'));
    }

    public function test_conflicting_end_times_fail_closed(): void
    {
        $projection = $this->project([
            $this->message(['end_at' => '2026-09-03T12:00:00Z']),
            $this->message([
                'external_message_id' => 'END-1',
                'type' => 'FAILURE_END',
                'end_at' => '2026-09-03T12:05:00Z',
                'published_at' => '2026-09-03T12:06:00Z',
            ]),
        ]);

        $this->assertTrue($projection->isAmbiguous());
        $this->assertSame([101], $projection->ambiguousEventIds);
    }

    public function test_duplicate_start_and_category_conflict_fail_closed(): void
    {
        $duplicate = $this->project([
            $this->message(),
            $this->message(['external_message_id' => 'START-2']),
        ]);
        $categoryConflict = $this->project([
            $this->message(),
            $this->message([
                'external_message_id' => 'TOTAL-1',
                'category' => 'TOTAL_FAILURE',
            ]),
        ]);

        $this->assertTrue($duplicate->isAmbiguous());
        $this->assertTrue($categoryConflict->isAmbiguous());
    }

    public function test_incompatible_end_chronology_fails_closed(): void
    {
        $projection = $this->project([
            $this->message(),
            $this->message([
                'external_message_id' => 'END-1',
                'type' => 'FAILURE_END',
                'start_at' => '2026-09-03T10:01:00Z',
                'end_at' => '2026-09-03T12:00:00Z',
                'published_at' => '2026-09-03T12:01:00Z',
            ]),
        ]);

        $this->assertTrue($projection->isAmbiguous());
    }

    public function test_other_environment_and_maintenance_are_not_projected(): void
    {
        $projection = $this->project([
            $this->message(['source_environment' => KsefLatarniaEnvironment::Production]),
            $this->message([
                'external_message_id' => 'MAINTENANCE-1',
                'event_id' => 202,
                'category' => 'MAINTENANCE',
                'type' => 'MAINTENANCE_ANNOUNCEMENT',
                'end_at' => '2026-09-03T12:00:00Z',
            ]),
        ]);

        $this->assertFalse($projection->isAmbiguous());
        $this->assertSame([], $projection->events);
    }

    private function project(array $messages)
    {
        return app(KsefLatarniaFailureEventProjector::class)->project(
            $messages,
            KsefLatarniaEnvironment::Test,
        );
    }

    private function message(array $overrides = []): KsefLatarniaMessage
    {
        $attributes = array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'START-1',
            'event_id' => 101,
            'version' => 1,
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'title' => 'Synthetic event',
            'text' => 'Synthetic event fixture.',
            'start_at' => '2026-09-03T10:00:00Z',
            'end_at' => null,
            'published_at' => '2026-09-03T10:01:00Z',
            'payload_json' => '{}',
            'payload_hash' => str_repeat('A', 44),
            'first_fetched_at' => '2026-09-03T10:02:00Z',
            'last_seen_at' => '2026-09-03T10:02:00Z',
        ], $overrides);

        foreach (['start_at', 'end_at', 'published_at', 'first_fetched_at', 'last_seen_at'] as $field) {
            if (is_string($attributes[$field])) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field]);
            }
        }

        return new KsefLatarniaMessage($attributes);
    }
}
