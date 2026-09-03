<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;

final readonly class KsefLatarniaFailureEvent
{
    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public int $eventId,
        public KsefLatarniaMessageCategory $category,
        public CarbonImmutable $startAt,
        public ?CarbonImmutable $endAt,
        public CarbonImmutable $publishedAt,
        public array $messageIds,
    ) {}
}
