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
        return $this->scaled($value, $scale);
    }

    public function add(string $left, string $right, int $scale = 2): string
    {
        try {
            return (string) $this->decimal($left)
                ->toScale($scale, RoundingMode::HalfUp)
                ->plus($this->decimal($right)->toScale($scale, RoundingMode::HalfUp))
                ->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
    }

    public function subtract(string $left, string $right, int $scale = 2): string
    {
        try {
            return (string) $this->decimal($left)
                ->toScale($scale, RoundingMode::HalfUp)
                ->minus($this->decimal($right)->toScale($scale, RoundingMode::HalfUp))
                ->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
    }

    public function multiply(string $left, string|int $right, int $scale = 2): string
    {
        try {
            return (string) $this->decimal($left)
                ->toScale($scale, RoundingMode::HalfUp)
                ->multipliedBy($this->decimal($right)->toScale($scale, RoundingMode::HalfUp))
                ->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
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
        try {
            return $this->decimal($left)
                ->toScale($scale, RoundingMode::HalfUp)
                ->compareTo($this->decimal($right)->toScale($scale, RoundingMode::HalfUp));
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
    }

    public function netFromGross(string $gross, string $vatRate, int $scale): string
    {
        try {
            $grossDecimal = $this->decimal($gross)->toScale($scale, RoundingMode::HalfUp);
            $rate = $this->decimal($vatRate)->toScale(2, RoundingMode::HalfUp);

            if ($grossDecimal->isNegative() || $rate->isNegative()) {
                throw $this->calculationException();
            }

            return (string) $grossDecimal
                ->multipliedBy(100)
                ->dividedBy(BigDecimal::of(100)->plus($rate), $scale, RoundingMode::HalfUp);
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
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

    private function scaled(string|int|null $value, int $scale): string
    {
        try {
            return (string) $this->decimal($value)->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->calculationException($exception);
        }
    }

    private function decimal(string|int|null $value): BigDecimal
    {
        return BigDecimal::of(str_replace(',', '.', trim((string) ($value ?? '0'))));
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
