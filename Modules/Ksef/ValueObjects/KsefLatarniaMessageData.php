<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;

final readonly class KsefLatarniaMessageData
{
    public function __construct(
        public string $externalMessageId,
        public int $eventId,
        public int $version,
        public KsefLatarniaMessageCategory $category,
        public KsefLatarniaMessageType $type,
        public string $title,
        public string $text,
        public CarbonImmutable $startAt,
        public ?CarbonImmutable $endAt,
        public CarbonImmutable $publishedAt,
        public string $payloadJson,
        public string $payloadHash,
    ) {}
}
