<?php

namespace Modules\Invoices\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Modules\Invoices\Exceptions\InvoiceDomainException;

class InvoiceFinancialValueValidator
{
    /**
     * @param  array{precision: int, scale: int, signed: bool}  $contract
     */
    public function assertFits(
        mixed $value,
        array $contract,
        string $message = 'Wartość finansowa przekracza maksymalny obsługiwany zakres.',
    ): string {
        try {
            $decimal = $this->decimal($value)->toScale($contract['scale'], RoundingMode::Unnecessary);
            $maximum = BigDecimal::of($this->maximum($contract['precision'], $contract['scale']));

            if ((! $contract['signed'] && $decimal->isNegative())
                || $decimal->abs()->isGreaterThan($maximum)) {
                throw $this->rangeException($message);
            }

            return (string) $decimal;
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (MathException|\InvalidArgumentException) {
            throw $this->rangeException($message);
        }
    }

    /**
     * @param  array{precision: int, scale: int, signed: bool}  $contract
     */
    public function fits(mixed $value, array $contract): bool
    {
        try {
            $this->assertFits($value, $contract);

            return true;
        } catch (InvoiceDomainException) {
            return false;
        }
    }

    public function assertOrderMoney(mixed $value, string $message): string
    {
        return $this->assertFits($value, InvoiceFinancialLimits::ORDER_MONEY, $message);
    }

    public function assertInvoiceItemQuantity(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::INVOICE_ITEM_QUANTITY,
            'Ilość przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertInvoiceItemUnitPrice(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::INVOICE_ITEM_UNIT_PRICE,
            'Cena brutto przekracza maksymalną obsługiwaną wartość.',
        );
    }

    public function assertInvoiceItemTotal(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::INVOICE_ITEM_TOTAL,
            'Wartość pozycji przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertInvoiceDocumentTotal(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::INVOICE_DOCUMENT_TOTAL,
            'Wartość dokumentu przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertCorrectionDifference(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::CORRECTION_DIFFERENCE,
            'Wartość Korekty przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertCorrectionQuantityDifference(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::CORRECTION_QUANTITY_DIFFERENCE,
            'Różnica ilości na Korekcie przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertCorrectionUnitPriceDifference(mixed $value): string
    {
        return $this->assertFits(
            $value,
            InvoiceFinancialLimits::CORRECTION_UNIT_PRICE_DIFFERENCE,
            'Różnica ceny na Korekcie przekracza maksymalny obsługiwany zakres.',
        );
    }

    public function assertVatPercentage(mixed $value): string
    {
        try {
            $decimal = $this->decimal($value)->toScale(0, RoundingMode::Unnecessary);

            if ($decimal->isNegative() || $decimal->isGreaterThan(BigDecimal::of(100))) {
                throw $this->vatException();
            }

            return (string) $decimal->toScale(2);
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (MathException|\InvalidArgumentException) {
            throw $this->vatException();
        }
    }

    public function isBusinessVatInput(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^(?:0|[1-9]\d{0,2})$/', $value)) {
            return false;
        }

        return (int) $value <= 100;
    }

    public function maximum(int $precision, int $scale): string
    {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new \InvalidArgumentException('Nieprawidłowy kontrakt wartości dziesiętnej.');
        }

        $integerDigits = $precision - $scale;
        $integer = $integerDigits === 0 ? '0' : str_repeat('9', $integerDigits);

        return $scale === 0 ? $integer : $integer.'.'.str_repeat('9', $scale);
    }

    private function decimal(mixed $value): BigDecimal
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException('Wartość nie jest dokładną liczbą dziesiętną.');
        }

        $value = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException('Wartość nie jest dokładną liczbą dziesiętną.');
        }

        return BigDecimal::of($value);
    }

    private function rangeException(string $message): InvoiceDomainException
    {
        return new InvoiceDomainException('invoice_financial_value_out_of_range', $message);
    }

    private function vatException(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_vat_rate_invalid',
            'Stawka VAT musi być liczbą całkowitą od 0 do 100%.',
        );
    }
}
