<?php

namespace Modules\Ksef\ValueObjects;

use Modules\Ksef\Enums\KsefLatarniaStatus;

final readonly class KsefLatarniaStatusData
{
    /**
     * @param  list<KsefLatarniaMessageData>  $messages
     */
    public function __construct(
        public KsefLatarniaStatus $status,
        public array $messages,
        public string $payloadJson,
        public string $payloadHash,
    ) {}
}
