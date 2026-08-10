<?php

namespace Modules\Invoices\Services;

use App\Support\CurrencyCatalog;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Throwable;

class CorrectionCurrencyConversionService
{
    public function __construct(
        private readonly InvoicePdfCurrencyConversionPresenter $presenter,
        private readonly InvoiceCurrencyConversionService $conversion,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $differenceTaxSummary
     * @return array<string, mixed>
     */
    public function metadataFor(
        Invoice $sourceInvoice,
        array $differenceTaxSummary,
        bool $monetary,
    ): array {
        if (! $monetary
            || strtoupper(trim((string) $sourceInvoice->currency)) === CurrencyCatalog::SYSTEM_CURRENCY) {
            return [];
        }

        $metadata = $sourceInvoice->tax_metadata_snapshot;
        if (! is_array($metadata) || $metadata === []) {
            throw new InvoiceDomainException(
                'correction_currency_snapshot_missing',
                'Nie można wystawić Korekty zmieniającej kwoty, ponieważ Faktura źródłowa nie posiada zapisanego historycznego kursu waluty.',
            );
        }

        try {
            if ($this->presenter->present($sourceInvoice) === null) {
                throw new InvoiceDomainException('correction_currency_snapshot_invalid', 'Nieprawidłowy snapshot kursu.');
            }

            return $this->conversion->recalculateWithHistoricalRate($metadata, $differenceTaxSummary);
        } catch (Throwable $exception) {
            if ($exception instanceof InvoiceDomainException
                && $exception->errorCode() === 'correction_currency_snapshot_missing') {
                throw $exception;
            }

            throw new InvoiceDomainException(
                'correction_currency_snapshot_invalid',
                'Nie można wystawić Korekty, ponieważ zapisane dane historycznego kursu Faktury źródłowej są niekompletne lub niespójne.',
                [],
                $exception,
            );
        }
    }
}
