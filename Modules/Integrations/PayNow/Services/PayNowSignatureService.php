<?php

namespace Modules\Integrations\PayNow\Services;

use InvalidArgumentException;
use JsonException;
use stdClass;

class PayNowSignatureService
{
    public function verifyNotification(string $rawBody, string $signatureKey, string $signature): bool
    {
        if ($signatureKey === '' || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $signatureKey, true));

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>>  $parameters
     *
     * @throws JsonException
     */
    public function generateV3(
        string $apiKey,
        string $signatureKey,
        string $idempotencyKey,
        string $body = '',
        array $parameters = [],
    ): string {
        if ($apiKey === '') {
            throw new InvalidArgumentException('PayNow API key is required.');
        }

        if ($signatureKey === '') {
            throw new InvalidArgumentException('PayNow signature key is required.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('PayNow idempotency key is required.');
        }

        $normalizedParameters = [];

        foreach ($parameters as $key => $value) {
            $values = is_array($value) ? array_values($value) : [$value];

            $normalizedParameters[$key] = array_map(
                static fn (string|int|float|bool $item): string => is_bool($item)
                    ? ($item ? '1' : '0')
                    : (string) $item,
                $values,
            );
        }

        ksort($normalizedParameters);

        $payload = [
            'headers' => [
                'Api-Key' => $apiKey,
                'Idempotency-Key' => $idempotencyKey,
            ],
            'parameters' => $normalizedParameters === [] ? new stdClass : $normalizedParameters,
            'body' => $body,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return base64_encode(hash_hmac('sha256', $json, $signatureKey, true));
    }
}
