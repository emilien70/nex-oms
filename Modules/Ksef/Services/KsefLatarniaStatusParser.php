<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefLatarniaStatusData;

final class KsefLatarniaStatusParser
{
    public function __construct(
        private readonly KsefLatarniaMessageParser $messages,
        private readonly KsefLatarniaPayloadCanonicalizer $canonicalizer,
    ) {}

    public function parse(mixed $payload): KsefLatarniaStatusData
    {
        $data = $this->objectData($payload);
        $statusValue = $data['status'] ?? null;

        if (! is_string($statusValue)) {
            $this->invalid();
        }

        $status = KsefLatarniaStatus::tryFrom($statusValue) ?? $this->invalid();
        $embedded = $data['messages'] ?? [];

        if ($embedded === null) {
            $embedded = [];
        }

        if (! is_array($embedded) || ! array_is_list($embedded)) {
            $this->invalid();
        }

        $messages = $this->messages->parseMany($embedded);
        $this->assertConsistent($status, $messages);
        $payloadJson = $this->canonicalizer->canonicalize($payload);

        return new KsefLatarniaStatusData(
            $status,
            $messages,
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

    private function assertConsistent(KsefLatarniaStatus $status, array $messages): void
    {
        if ($status === KsefLatarniaStatus::Available) {
            if ($messages !== []) {
                $this->invalid();
            }

            return;
        }

        $expectedCategory = match ($status) {
            KsefLatarniaStatus::Maintenance => KsefLatarniaMessageCategory::Maintenance,
            KsefLatarniaStatus::Failure => KsefLatarniaMessageCategory::Failure,
            KsefLatarniaStatus::TotalFailure => KsefLatarniaMessageCategory::TotalFailure,
            KsefLatarniaStatus::Available => null,
        };

        foreach ($messages as $message) {
            if ($message->category === $expectedCategory) {
                return;
            }
        }

        $this->invalid();
    }

    private function invalid(): never
    {
        throw new KsefApiException(
            'Latarnia KSeF zwróciła nieprawidłowy status dostępności.',
            'ksef_latarnia_response_invalid',
        );
    }
}
