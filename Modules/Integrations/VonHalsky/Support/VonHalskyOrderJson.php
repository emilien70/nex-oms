<?php

namespace Modules\Integrations\VonHalsky\Support;

use JsonException;

class VonHalskyOrderJson
{
    public static function decode(string $json): array
    {
        // Validate with PHP's parser, then preserve numeric lexemes before the authoritative decode.
        $typed = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $preserved = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            static fn (array $token): string => str_starts_with($token[0], '"')
                ? $token[0] : json_encode($token[0], JSON_THROW_ON_ERROR),
            $json,
        );
        if ($preserved === null) {
            throw new JsonException('Invalid JSON token stream.');
        }
        $data = json_decode($preserved, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new JsonException('Expected an order object.');
        }

        return self::amounts($typed, $data);
    }

    private static function amounts(array $typed, array $lexemes): array
    {
        foreach ($typed as $key => $value) {
            if ($key === 'amount' && (is_int($value) || is_float($value))) {
                $typed[$key] = $lexemes[$key];
            } elseif (is_array($value)) {
                $typed[$key] = self::amounts($value, $lexemes[$key]);
            }
        }

        return $typed;
    }
}
