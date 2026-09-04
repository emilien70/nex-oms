<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class KsefLatarniaMaintenanceEvent
{
    public function __construct(
        public int $eventId,
        public string $messageId,
        public int $messageVersion,
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public CarbonImmutable $publishedAt,
        public CarbonImmutable $firstFetchedAt,
    ) {}
}
