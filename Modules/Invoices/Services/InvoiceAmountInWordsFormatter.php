<?php

namespace Modules\Invoices\Services;

use InvalidArgumentException;

class InvoiceAmountInWordsFormatter
{
    /** @var array<int, string> */
    private const ONES = [
        0 => '', 1 => 'jeden', 2 => 'dwa', 3 => 'trzy', 4 => 'cztery', 5 => 'pięć',
        6 => 'sześć', 7 => 'siedem', 8 => 'osiem', 9 => 'dziewięć',
    ];

    /** @var array<int, string> */
    private const TEENS = [
        10 => 'dziesięć', 11 => 'jedenaście', 12 => 'dwanaście', 13 => 'trzynaście',
        14 => 'czternaście', 15 => 'piętnaście', 16 => 'szesnaście', 17 => 'siedemnaście',
        18 => 'osiemnaście', 19 => 'dziewiętnaście',
    ];

    /** @var array<int, string> */
    private const TENS = [
        2 => 'dwadzieścia', 3 => 'trzydzieści', 4 => 'czterdzieści', 5 => 'pięćdziesiąt',
        6 => 'sześćdziesiąt', 7 => 'siedemdziesiąt', 8 => 'osiemdziesiąt', 9 => 'dziewięćdziesiąt',
    ];

    /** @var array<int, string> */
    private const HUNDREDS = [
        1 => 'sto', 2 => 'dwieście', 3 => 'trzysta', 4 => 'czterysta', 5 => 'pięćset',
        6 => 'sześćset', 7 => 'siedemset', 8 => 'osiemset', 9 => 'dziewięćset',
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private const SCALES = [
        1 => ['tysiąc', 'tysiące', 'tysięcy'],
        2 => ['milion', 'miliony', 'milionów'],
        3 => ['miliard', 'miliardy', 'miliardów'],
        4 => ['bilion', 'biliony', 'bilionów'],
    ];

    public function format(string $amount, string $currency): string
    {
        [$negative, $whole, $fraction] = $this->parts($amount);
        $words = $this->wholeToWords($whole);
        $currency = strtoupper(trim($currency)) ?: 'PLN';
        $result = ($negative ? '- ' : '').$words.' '.$currency.' '.$fraction.'/100 '.$currency;

        return $this->upperFirst($result);
    }

    /** @return array{bool, string, string} */
    private function parts(string $amount): array
    {
        $normalized = str_replace(',', '.', trim($amount));

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Nieprawidłowy format kwoty.');
        }

        $negative = $matches[1] === '-';
        $whole = ltrim($matches[2], '0') ?: '0';
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
            if ($cents === 100) {
                $whole = $this->increment($whole);
                $cents = 0;
            }
        }

        return [$negative && ($whole !== '0' || $cents !== 0), $whole, str_pad((string) $cents, 2, '0', STR_PAD_LEFT)];
    }

    private function wholeToWords(string $whole): string
    {
        if ($whole === '0') {
            return 'zero';
        }

        $groups = [];
        while ($whole !== '') {
            $groups[] = (int) substr($whole, -3);
            $whole = substr($whole, 0, max(0, strlen($whole) - 3));
        }

        if (count($groups) > count(self::SCALES) + 1) {
            throw new InvalidArgumentException('Kwota jest zbyt duża.');
        }

        $parts = [];
        for ($scale = count($groups) - 1; $scale >= 0; $scale--) {
            $value = $groups[$scale];
            if ($value === 0) {
                continue;
            }

            $parts[] = $this->triad($value);
            if ($scale > 0) {
                $parts[] = self::SCALES[$scale][$this->scaleForm($value)];
            }
        }

        return implode(' ', $parts);
    }

    private function triad(int $value): string
    {
        $parts = [];
        $hundreds = intdiv($value, 100);
        $remainder = $value % 100;

        if ($hundreds > 0) {
            $parts[] = self::HUNDREDS[$hundreds];
        }

        if ($remainder >= 10 && $remainder <= 19) {
            $parts[] = self::TEENS[$remainder];
        } else {
            $tens = intdiv($remainder, 10);
            $ones = $remainder % 10;
            if ($tens > 0) {
                $parts[] = self::TENS[$tens];
            }
            if ($ones > 0) {
                $parts[] = self::ONES[$ones];
            }
        }

        return implode(' ', $parts);
    }

    private function scaleForm(int $value): int
    {
        if ($value === 1) {
            return 0;
        }

        $lastTwo = $value % 100;
        $last = $value % 10;

        return $lastTwo < 12 || $lastTwo > 14
            ? (in_array($last, [2, 3, 4], true) ? 1 : 2)
            : 2;
    }

    private function increment(string $digits): string
    {
        $carry = 1;
        $result = '';

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $value = (int) $digits[$index] + $carry;
            $result = (string) ($value % 10).$result;
            $carry = intdiv($value, 10);
        }

        return ($carry > 0 ? '1' : '').$result;
    }

    private function upperFirst(string $value): string
    {
        if (str_starts_with($value, '- ')) {
            return '- '.mb_strtoupper(mb_substr($value, 2, 1)).mb_substr($value, 3);
        }

        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
