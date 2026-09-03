<?php

namespace Modules\Ksef\Services;

use JsonException;
use Modules\Ksef\Exceptions\KsefApiException;
use stdClass;

final class KsefLatarniaPayloadCanonicalizer
{
    public function canonicalize(mixed $payload): string
    {
        try {
            return json_encode(
                $this->normalize($payload),
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new KsefApiException(
                'Latarnia KSeF zwróciła odpowiedź w nieprawidłowym formacie.',
                'ksef_latarnia_response_invalid',
            );
        }
    }

    public function hash(string $canonicalPayload): string
    {
        return base64_encode(hash('sha256', $canonicalPayload, true));
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map(fn (mixed $item): mixed => $this->normalize($item), $properties);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
            }

            ksort($value, SORT_STRING);

            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new KsefApiException(
            'Latarnia KSeF zwróciła odpowiedź w nieprawidłowym formacie.',
            'ksef_latarnia_response_invalid',
        );
    }
}
