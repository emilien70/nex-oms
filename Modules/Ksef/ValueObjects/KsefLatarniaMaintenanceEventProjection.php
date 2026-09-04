<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefLatarniaMaintenanceEventProjection
{
    /**
     * @param  list<KsefLatarniaMaintenanceEvent>  $events
     * @param  list<int>  $ambiguousEventIds
     * @param  list<string>  $ambiguousMessageIds
     */
    public function __construct(
        public array $events,
        public array $ambiguousEventIds = [],
        public array $ambiguousMessageIds = [],
    ) {}

    public function isAmbiguous(): bool
    {
        return $this->ambiguousEventIds !== [] || $this->ambiguousMessageIds !== [];
    }
}
