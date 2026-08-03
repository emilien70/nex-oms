<?php

namespace Modules\Invoices\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Modules\Invoices\Exceptions\InvoiceDomainException;

class InvoiceDecimalCalculator
{
    public function normalize(string|int|null $value, int $scale): string
    {
        return $this->formatScaled($this->toScaledInteger($value, $scale), $scale);
    }

    public function add(string $left, string $right, int $scale = 2): string
    {
        return $this->formatScaled(
            $this->toScaledInteger($left, $scale) + $this->toScaledInteger($right, $scale),
            $scale,
        );
    }

    public function subtract(string $left, string $right, int $scale = 2): string
    {
        return $this->formatScaled(
            $this->toScaledInteger($left, $scale) - $this->toScaledInteger($right, $scale),
            $scale,
        );
    }

    public function multiply(string $left, string|int $right, int $scale = 2): string
    {
        $leftScaled = $this->toScaledInteger($left, $scale);
        $rightScaled = $this->toScaledInteger($right, $scale);
        $factor = 10 ** $scale;

        if ($leftScaled !== 0 && abs($rightScaled) > intdiv(PHP_INT_MAX, abs($leftScaled))) {
            throw $this->calculationException();
        }

        return $this->formatScaled(
            $this->divideHalfUpSigned($leftScaled * $rightScaled, $factor),
            $scale,
        );
    }

    public function multiplyAndRound(string $left, string $right, int $resultScale = 2): string
    {
        try {
            return (string) BigDecimal::of($left)
                ->multipliedBy(BigDecimal::of($right))
                ->toScale($resultScale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
    }

    public function compare(string $left, string $right, int $scale = 2): int
    {
        return $this->toScaledInteger($left, $scale) <=> $this->toScaledInteger($right, $scale);
    }

    public function netFromGross(string $gross, string $vatRate, int $scale): string
    {
        $grossScaled = $this->toScaledInteger($gross, $scale);
        $rateBasisPoints = $this->toScaledInteger($vatRate, 2);

        if ($grossScaled < 0 || $rateBasisPoints < 0) {
            throw $this->calculationException();
        }

        $denominator = 10_000 + $rateBasisPoints;
        if ($denominator <= 0 || $grossScaled > intdiv(PHP_INT_MAX, 10_000)) {
            throw $this->calculationException();
        }

        return $this->formatScaled(
            $this->divideHalfUp($grossScaled * 10_000, $denominator),
            $scale,
        );
    }

    public function min(string $left, string $right, int $scale = 2): string
    {
        return $this->compare($left, $right, $scale) <= 0
            ? $this->normalize($left, $scale)
            : $this->normalize($right, $scale);
    }

    public function max(string $left, string $right, int $scale = 2): string
    {
        return $this->compare($left, $right, $scale) >= 0
            ? $this->normalize($left, $scale)
            : $this->normalize($right, $scale);
    }

    private function toScaledInteger(string|int|null $value, int $scale): int
    {
        $normalized = str_replace(',', '.', trim((string) ($value ?? '0')));

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $normalized, $matches)) {
            throw $this->calculationException();
        }

        $negative = $matches[1] === '-';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $keptFraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $digits = ltrim($whole.$keptFraction, '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw $this->calculationException();
        }

        $scaled = (int) $digits;
        if (isset($fraction[$scale]) && (int) $fraction[$scale] >= 5) {
            $scaled++;
        }

        return $negative ? -$scaled : $scaled;
    }

    private function formatScaled(int $value, int $scale): string
    {
        $negative = $value < 0;
        $digits = (string) abs($value);

        if ($scale === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return $remainder * 2 >= $denominator ? $quotient + 1 : $quotient;
    }

    private function divideHalfUpSigned(int $numerator, int $denominator): int
    {
        $negative = $numerator < 0;
        $rounded = $this->divideHalfUp(abs($numerator), $denominator);

        return $negative ? -$rounded : $rounded;
    }

    private function calculationException(?\Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_tax_calculation_failed',
            'Nie można prawidłowo obliczyć wartości podatkowych dokumentu.',
            [],
            $previous,
        );
    }
}
