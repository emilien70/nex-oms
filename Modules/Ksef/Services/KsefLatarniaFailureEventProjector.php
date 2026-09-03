<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\ValueObjects\KsefLatarniaFailureEvent;
use Modules\Ksef\ValueObjects\KsefLatarniaFailureEventProjection;

final class KsefLatarniaFailureEventProjector
{
    /**
     * @param  iterable<KsefLatarniaMessage>  $messages
     */
    public function project(
        iterable $messages,
        KsefLatarniaEnvironment $environment,
    ): KsefLatarniaFailureEventProjection {
        $latest = $this->latestMessages($messages, $environment);
        $ambiguousEventIds = [];
        $ambiguousMessageIds = [];
        $events = [];

        foreach ($latest->groupBy('event_id') as $eventId => $group) {
            $categories = $group->map(fn (KsefLatarniaMessage $message): string => $message->category->value)
                ->unique()
                ->values();

            if ($categories->count() !== 1) {
                $ambiguousEventIds[] = (int) $eventId;
                $ambiguousMessageIds = [...$ambiguousMessageIds, ...$group->pluck('external_message_id')->all()];

                continue;
            }

            $category = $group->first()->category;

            if ($category === KsefLatarniaMessageCategory::Maintenance) {
                continue;
            }

            $projected = $this->projectEvent($group);

            if ($projected === null) {
                $ambiguousEventIds[] = (int) $eventId;
                $ambiguousMessageIds = [...$ambiguousMessageIds, ...$group->pluck('external_message_id')->all()];

                continue;
            }

            $events[] = $projected;
        }

        usort($events, fn (KsefLatarniaFailureEvent $left, KsefLatarniaFailureEvent $right): int => $left->publishedAt->getTimestamp() <=> $right->publishedAt->getTimestamp()
                ?: $left->eventId <=> $right->eventId);

        return new KsefLatarniaFailureEventProjection(
            $events,
            array_values(array_unique($ambiguousEventIds)),
            array_values(array_unique($ambiguousMessageIds)),
        );
    }

    /**
     * @param  iterable<KsefLatarniaMessage>  $messages
     * @return Collection<int, KsefLatarniaMessage>
     */
    private function latestMessages(iterable $messages, KsefLatarniaEnvironment $environment): Collection
    {
        return collect($messages)
            ->filter(fn (mixed $message): bool => $message instanceof KsefLatarniaMessage
                && $message->source_environment === $environment)
            ->groupBy('external_message_id')
            ->map(fn (Collection $versions): KsefLatarniaMessage => $versions
                ->sortByDesc(fn (KsefLatarniaMessage $message): int => $message->version)
                ->first())
            ->values();
    }

    /**
     * @param  Collection<int, KsefLatarniaMessage>  $messages
     */
    private function projectEvent(Collection $messages): ?KsefLatarniaFailureEvent
    {
        $starts = $messages->where('type', KsefLatarniaMessageType::FailureStart)->values();
        $ends = $messages->where('type', KsefLatarniaMessageType::FailureEnd)->values();

        if ($starts->count() !== 1 || $ends->count() > 1) {
            return null;
        }

        /** @var KsefLatarniaMessage $start */
        $start = $starts->first();
        /** @var KsefLatarniaMessage|null $end */
        $end = $ends->first();

        if ($end !== null
            && (! $end->start_at->equalTo($start->start_at)
                || $end->published_at->lessThan($start->published_at))) {
            return null;
        }

        $endCandidates = collect([$start->end_at, $end?->end_at])->filter();

        if ($endCandidates->map(fn ($instant): int => $instant->getTimestamp())->unique()->count() > 1) {
            return null;
        }

        $endAt = $endCandidates->first();

        if ($endAt !== null && $endAt->lessThan($start->start_at)) {
            return null;
        }

        return new KsefLatarniaFailureEvent(
            (int) $start->event_id,
            $start->category,
            $start->start_at,
            $endAt,
            $start->published_at,
            $messages->pluck('external_message_id')->sort()->values()->all(),
        );
    }
}
