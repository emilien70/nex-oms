<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Ksef\Exceptions\KsefApiException;
use Throwable;

class KsefUserErrorPresenter
{
    public const OPERATION_SUBMIT_INVOICE = 'submit_invoice';

    public const OPERATION_RECONCILE = 'reconcile';

    public const OPERATION_REFRESH = 'refresh';

    public const OPERATION_FETCH_UPO = 'fetch_upo';

    private const FIELD_LABELS = [
        'seller.name' => 'nazwa sprzedawcy',
        'seller.tax_id' => 'NIP sprzedawcy',
        'seller.country_code' => 'kraj sprzedawcy',
        'seller.address' => 'adres sprzedawcy',
        'buyer.name' => 'nazwa nabywcy',
        'buyer.country_code' => 'kraj nabywcy',
        'buyer.address' => 'adres nabywcy',
    ];

    private const REASON_DETAILS = [
        'tax_treatment_count_mismatch' => 'Liczba klasyfikacji podatkowych nie odpowiada liczbie pozycji Faktury.',
        'tax_treatment_missing' => 'Brakuje zapisanej klasyfikacji podatkowej tej pozycji.',
        'tax_treatment_inconsistent' => 'Klasyfikacja podatkowa tej pozycji jest niespójna z danymi Faktury.',
        'unsupported_vat_code' => 'Kod VAT tej pozycji nie jest obsługiwany przez aktualny profil FA(3).',
        'unsupported_vat_rate' => 'Stawka VAT tej pozycji nie jest obsługiwana przez aktualny profil FA(3).',
    ];

    /**
     * @return array{
     *     title: string,
     *     stage: string,
     *     code: string,
     *     code_label: string,
     *     message: string,
     *     details: list<string>,
     *     http_status: ?int,
     *     reason_code: ?string
     * }
     */
    public function present(Throwable $exception, string $operation): array
    {
        if ($exception instanceof InvoiceDomainException) {
            return [
                'title' => $this->title($operation),
                'stage' => $this->domainStage($exception->errorCode()),
                'code' => $exception->errorCode(),
                'code_label' => 'Kod',
                'message' => $exception->getMessage(),
                'details' => $this->domainDetails($exception),
                'http_status' => null,
                'reason_code' => null,
            ];
        }

        if ($exception instanceof KsefApiException) {
            return [
                'title' => $this->title($operation),
                'stage' => 'Komunikacja z KSeF',
                'code' => $exception->safeCode,
                'code_label' => 'Kod NEX',
                'message' => $exception->getMessage(),
                'details' => [],
                'http_status' => $exception->httpStatus,
                'reason_code' => $exception->reasonCode,
            ];
        }

        return [
            'title' => $this->title($operation),
            'stage' => 'Operacja KSeF',
            'code' => 'ksef_operation_failed',
            'code_label' => 'Kod',
            'message' => 'Nie udało się wykonać operacji KSeF.',
            'details' => [],
            'http_status' => null,
            'reason_code' => null,
        ];
    }

    private function title(string $operation): string
    {
        return match ($operation) {
            self::OPERATION_SUBMIT_INVOICE => 'Nie udało się przekazać Faktury do KSeF',
            self::OPERATION_RECONCILE => 'Nie udało się sprawdzić transmisji KSeF',
            self::OPERATION_REFRESH => 'Nie udało się odświeżyć statusu KSeF',
            self::OPERATION_FETCH_UPO => 'Nie udało się pobrać UPO z KSeF',
            default => 'Nie udało się wykonać operacji KSeF',
        };
    }

    private function domainStage(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'ksef_fa3_seller_') => 'Dane sprzedawcy',
            $code === 'ksef_fa3_buyer_incomplete' => 'Dane nabywcy',
            str_starts_with($code, 'ksef_fa3_buyer_') => 'Dane nabywcy / identyfikacja podatkowa',
            $code === 'ksef_fa3_financial_snapshot_invalid' => 'Weryfikacja wartości finansowych FA(3)',
            $code === 'ksef_fa3_currency_snapshot_invalid' => 'Przeliczenie waluty do PLN dla FA(3)',
            str_contains($code, 'schema') || str_contains($code, 'xml') => 'Walidacja XML FA(3)',
            in_array($code, [
                'ksef_fa3_tax_snapshot_missing',
                'ksef_fa3_tax_snapshot_invalid',
                'ksef_fa3_unsupported_vat_code',
                'ksef_fa3_unsupported_vat_rate',
            ], true) => 'Weryfikacja danych podatkowych FA(3)',
            default => 'Przygotowanie dokumentu FA(3)',
        };
    }

    /** @return list<string> */
    private function domainDetails(InvoiceDomainException $exception): array
    {
        $metadata = $exception->metadata();
        $details = [];

        if ($exception->errorCode() === 'ksef_fa3_items_missing'
            && ($metadata['item_count'] ?? null) === 0) {
            $details[] = 'Aby przygotować dokument FA(3), Faktura musi zawierać co najmniej jedną pozycję.';
        }

        $this->appendFieldDetails($details, 'Brakujące dane', $metadata['missing_fields'] ?? null);
        $this->appendFieldDetails($details, 'Nieprawidłowe dane', $metadata['invalid_fields'] ?? null);

        $invoiceItem = $metadata['invoice_item'] ?? null;
        if (is_array($invoiceItem)) {
            $position = filter_var($invoiceItem['position'] ?? null, FILTER_VALIDATE_INT);
            $name = $this->safeString($invoiceItem['name'] ?? null, 160);

            if ($position !== false && $position > 0) {
                $item = 'Pozycja '.$position;
                if ($name !== null) {
                    $item .= ': '.$name;
                }
                $details[] = 'Problem dotyczy: '.$item;
            }
        }

        $reason = $metadata['reason'] ?? null;
        if (is_string($reason) && isset(self::REASON_DETAILS[$reason])) {
            $details[] = 'Powód: '.self::REASON_DETAILS[$reason];
        }

        $vatCode = $this->safeString($metadata['vat_code'] ?? null, 32);
        if ($vatCode !== null) {
            $details[] = 'Kod VAT: '.$vatCode;
        }

        $vatRate = $this->safeString($metadata['vat_rate'] ?? null, 32);
        if ($vatRate !== null) {
            $details[] = 'VAT: '.$this->decimalLabel($vatRate).'%';
        }

        return array_values(array_unique($details));
    }

    /**
     * @param  list<string>  $details
     */
    private function appendFieldDetails(array &$details, string $prefix, mixed $fields): void
    {
        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $field) {
            if (is_string($field) && isset(self::FIELD_LABELS[$field])) {
                $details[] = $prefix.': '.self::FIELD_LABELS[$field];
            }
        }
    }

    private function safeString(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $limit, 'UTF-8');
    }

    private function decimalLabel(string $value): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        if (! str_contains($value, '.')) {
            return $value === '-0' ? '0' : $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }
}
