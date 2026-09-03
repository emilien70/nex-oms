<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefLatarniaMessageData;
use Throwable;

final class KsefLatarniaMessageParser
{
    public function __construct(
        private readonly KsefLatarniaPayloadCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  list<mixed>  $messages
     * @return list<KsefLatarniaMessageData>
     */
    public function parseMany(array $messages): array
    {
        if (! array_is_list($messages)) {
            $this->invalid();
        }

        return array_map(fn (mixed $message): KsefLatarniaMessageData => $this->parse($message), $messages);
    }

    public function parse(mixed $payload): KsefLatarniaMessageData
    {
        $message = $this->objectData($payload);
        $externalMessageId = $this->requiredString($message, 'id', 24);
        $eventId = $this->requiredPositiveInteger($message, 'eventId');
        $version = $this->requiredPositiveInteger($message, 'version');
        $category = $this->category($message['category'] ?? null);
        $type = $this->type($message['type'] ?? null);

        $this->assertCategoryType($category, $type);

        $title = $this->requiredString($message, 'title', 80);
        $text = $this->requiredString($message, 'text', 3000);
        $startAt = $this->requiredInstant($message, 'start');
        $endAt = $this->endInstant($message, $type);
        $publishedAt = $this->requiredInstant($message, 'published');

        $this->assertPeriod($category, $type, $startAt, $endAt);

        $payloadJson = $this->canonicalizer->canonicalize($payload);

        return new KsefLatarniaMessageData(
            $externalMessageId,
            $eventId,
            $version,
            $category,
            $type,
            $title,
            $text,
            $startAt,
            $endAt,
            $publishedAt,
            $payloadJson,
            $this->canonicalizer->hash($payloadJson),
        );
    }

    private function objectData(mixed $payload): array
    {
        if (is_object($payload)) {
            return get_object_vars($payload);
        }

        if (is_array($payload) && ! array_is_list($payload)) {
            return $payload;
        }

        $this->invalid();
    }

    private function requiredString(array $message, string $key, int $maxLength): string
    {
        $value = $message[$key] ?? null;

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            $this->invalid();
        }

        return $value;
    }

    private function requiredPositiveInteger(array $message, string $key): int
    {
        $value = $message[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            $this->invalid();
        }

        return $value;
    }

    private function category(mixed $value): KsefLatarniaMessageCategory
    {
        if (! is_string($value)) {
            $this->invalid();
        }

        return KsefLatarniaMessageCategory::tryFrom($value) ?? $this->invalid();
    }

    private function type(mixed $value): KsefLatarniaMessageType
    {
        if (! is_string($value)) {
            $this->invalid();
        }

        return KsefLatarniaMessageType::tryFrom($value) ?? $this->invalid();
    }

    private function assertCategoryType(
        KsefLatarniaMessageCategory $category,
        KsefLatarniaMessageType $type,
    ): void {
        $valid = match ($category) {
            KsefLatarniaMessageCategory::Maintenance => $type === KsefLatarniaMessageType::MaintenanceAnnouncement,
            KsefLatarniaMessageCategory::Failure,
            KsefLatarniaMessageCategory::TotalFailure => in_array($type, [
                KsefLatarniaMessageType::FailureStart,
                KsefLatarniaMessageType::FailureEnd,
            ], true),
        };

        if (! $valid) {
            $this->invalid();
        }
    }

    private function requiredInstant(array $message, string $key): CarbonImmutable
    {
        if (! array_key_exists($key, $message)) {
            $this->invalid();
        }

        return $this->instant($message[$key]);
    }

    private function endInstant(array $message, KsefLatarniaMessageType $type): ?CarbonImmutable
    {
        if (! array_key_exists('end', $message) || $message['end'] === null) {
            if ($type === KsefLatarniaMessageType::FailureStart) {
                return null;
            }

            $this->invalid();
        }

        return $this->instant($message['end']);
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            $this->invalid();
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            $this->invalid();
        }
    }

    private function assertPeriod(
        KsefLatarniaMessageCategory $category,
        KsefLatarniaMessageType $type,
        CarbonImmutable $startAt,
        ?CarbonImmutable $endAt,
    ): void {
        if ($type === KsefLatarniaMessageType::MaintenanceAnnouncement) {
            if ($category !== KsefLatarniaMessageCategory::Maintenance
                || $endAt === null
                || ! $endAt->greaterThan($startAt)) {
                $this->invalid();
            }

            return;
        }

        if ($endAt !== null && $endAt->lessThan($startAt)) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new KsefApiException(
            'Latarnia KSeF zwróciła niekompletny lub nieprawidłowy komunikat.',
            'ksef_latarnia_response_invalid',
        );
    }
}
