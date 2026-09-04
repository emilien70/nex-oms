<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\ValueObjects\KsefLatarniaMaintenanceEvent;
use Modules\Ksef\ValueObjects\KsefLatarniaMaintenanceEventProjection;

final class KsefLatarniaMaintenanceEventProjector
{
    /** @param iterable<KsefLatarniaMessage> $messages */
    public function project(
        iterable $messages,
        KsefLatarniaEnvironment $environment,
    ): KsefLatarniaMaintenanceEventProjection {
        $latest = collect($messages)
            ->filter(fn (mixed $message): bool => $message instanceof KsefLatarniaMessage
                && $message->source_environment === $environment
                && $message->category === KsefLatarniaMessageCategory::Maintenance)
            ->groupBy('external_message_id')
            ->map(fn (Collection $versions): KsefLatarniaMessage => $versions
                ->sortByDesc(fn (KsefLatarniaMessage $message): int => $message->version)
                ->first())
            ->values();
        $events = [];
        $ambiguousEventIds = [];
        $ambiguousMessageIds = [];

        foreach ($latest->groupBy('event_id') as $eventId => $group) {
            if ($group->count() !== 1) {
                $ambiguousEventIds[] = (int) $eventId;
                $ambiguousMessageIds = [...$ambiguousMessageIds, ...$group->pluck('external_message_id')->all()];

                continue;
            }

            /** @var KsefLatarniaMessage $message */
            $message = $group->first();

            if ($message->type !== KsefLatarniaMessageType::MaintenanceAnnouncement
                || $message->end_at === null
                || $message->end_at->lessThan($message->start_at)) {
                $ambiguousEventIds[] = (int) $eventId;
                $ambiguousMessageIds[] = $message->external_message_id;

                continue;
            }

            $events[] = new KsefLatarniaMaintenanceEvent(
                (int) $message->event_id,
                $message->external_message_id,
                $message->version,
                $message->start_at,
                $message->end_at,
                $message->published_at,
                $message->first_fetched_at,
            );
        }

        usort($events, fn (KsefLatarniaMaintenanceEvent $left, KsefLatarniaMaintenanceEvent $right): int => $left->startAt->getTimestamp() <=> $right->startAt->getTimestamp()
            ?: $left->eventId <=> $right->eventId);

        return new KsefLatarniaMaintenanceEventProjection(
            $events,
            array_values(array_unique($ambiguousEventIds)),
            array_values(array_unique($ambiguousMessageIds)),
        );
    }
}
