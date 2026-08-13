<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Exceptions\KsefApiException;

final class KsefEcdsaSignatureConverter
{
    public function derToRaw(string $signature, int $fieldSize = 32): string
    {
        $offset = 0;
        $sequence = $this->readValue($signature, $offset, 0x30);

        if ($offset !== strlen($signature)) {
            $this->fail();
        }

        $sequenceOffset = 0;
        $r = $this->normalizeInteger($this->readValue($sequence, $sequenceOffset, 0x02), $fieldSize);
        $s = $this->normalizeInteger($this->readValue($sequence, $sequenceOffset, 0x02), $fieldSize);

        if ($sequenceOffset !== strlen($sequence)) {
            $this->fail();
        }

        return str_pad($r, $fieldSize, "\0", STR_PAD_LEFT)
            .str_pad($s, $fieldSize, "\0", STR_PAD_LEFT);
    }

    public function rawToDer(string $signature): string
    {
        if ($signature === '' || strlen($signature) % 2 !== 0) {
            $this->fail();
        }

        $fieldSize = intdiv(strlen($signature), 2);
        $r = $this->encodeInteger(substr($signature, 0, $fieldSize));
        $s = $this->encodeInteger(substr($signature, $fieldSize));
        $sequence = "\x02".$this->encodeLength(strlen($r)).$r
            ."\x02".$this->encodeLength(strlen($s)).$s;

        return "\x30".$this->encodeLength(strlen($sequence)).$sequence;
    }

    private function readValue(string $der, int &$offset, int $expectedTag): string
    {
        if ($offset >= strlen($der) || ord($der[$offset++]) !== $expectedTag) {
            $this->fail();
        }

        $length = $this->readLength($der, $offset);

        if ($length < 1 || $offset + $length > strlen($der)) {
            $this->fail();
        }

        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function readLength(string $der, int &$offset): int
    {
        if ($offset >= strlen($der)) {
            $this->fail();
        }

        $first = ord($der[$offset++]);

        if (($first & 0x80) === 0) {
            return $first;
        }

        $bytes = $first & 0x7F;

        if ($bytes === 0 || $bytes > 4 || $offset + $bytes > strlen($der)) {
            $this->fail();
        }

        if (ord($der[$offset]) === 0) {
            $this->fail();
        }

        $length = 0;

        for ($index = 0; $index < $bytes; $index++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        if ($length < 128) {
            $this->fail();
        }

        return $length;
    }

    private function normalizeInteger(string $integer, int $fieldSize): string
    {
        if ($integer === '' || (ord($integer[0]) & 0x80) !== 0) {
            $this->fail();
        }

        if (strlen($integer) > 1 && $integer[0] === "\0") {
            if ((ord($integer[1]) & 0x80) === 0) {
                $this->fail();
            }

            $integer = substr($integer, 1);
        }

        if (strlen($integer) > $fieldSize || trim($integer, "\0") === '') {
            $this->fail();
        }

        return $integer;
    }

    private function encodeInteger(string $integer): string
    {
        $integer = ltrim($integer, "\0");
        $integer = $integer === '' ? "\0" : $integer;

        if ((ord($integer[0]) & 0x80) !== 0) {
            $integer = "\0".$integer;
        }

        return $integer;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';

        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function fail(): never
    {
        throw new KsefApiException(
            'Nie udało się przetworzyć podpisu ECDSA certyfikatu KSeF.',
            'ecdsa_signature_malformed',
        );
    }
}
