<?php

namespace Modules\Integrations\MacroDroid\Services;

class SmsSegments
{
    /** @return array{encoding: string, units: int, parts: int} */
    public function measure(string $body): array
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extended = "\f^{}\\[~]|€";
        $units = 0;
        $weights = [];
        $characters = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($characters as $character) {
            if (str_contains($basic, $character)) {
                $units++;
                $weights[] = 1;
            } elseif (str_contains($extended, $character)) {
                $units += 2;
                $weights[] = 2;
            } else {
                $weights = array_map(static fn ($char): int => (int) (strlen(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')) / 2), $characters);
                $units = array_sum($weights);

                return ['encoding' => 'UTF-16', 'units' => $units, 'parts' => $units <= 70 ? 1 : $this->parts($weights, 67)];
            }
        }

        return ['encoding' => 'GSM-7', 'units' => $units, 'parts' => $units <= 160 ? 1 : $this->parts($weights, 153)];
    }

    private function parts(array $weights, int $capacity): int
    {
        $parts = 1;
        $used = 0;
        // Neither GSM escape sequences nor UTF-16 surrogate pairs may straddle a segment.
        foreach ($weights as $weight) {
            if ($used + $weight > $capacity) {
                $parts++;
                $used = 0;
            }
            $used += $weight;
        }

        return $parts;
    }
}
