<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\InvoiceCurrencyConversionContext;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionDispatcher;
use Modules\Ksef\Services\KsefFa3SemanticSnapshotService;

class InvoiceIssuingService
{
    private const MAX_CONTEXT_ATTEMPTS = 2;

    public function __construct(
        private readonly InvoiceDocumentPreparationService $preparation,
        private readonly InvoiceNumberingService $numbering,
        private readonly InvoiceCurrencyConversionService $currencyConversion,
        private readonly KsefFa3SemanticSnapshotService $ksefSemanticSnapshot,
        private readonly KsefAutomaticInvoiceSubmissionDispatcher $automaticKsefSubmissions,
    ) {}

    public function issue(Order $order, InvoiceSeries $series, InvoiceOperationContext $context): Invoice
    {
        $this->assertInvoiceDoesNotExist($order);
        $this->assertSeries($series);

        try {
            for ($attempt = 1; $attempt <= self::MAX_CONTEXT_ATTEMPTS; $attempt++) {
                $currentOrder = Order::query()->findOrFail($order->getKey());
                $currentSeries = InvoiceSeries::query()->findOrFail($series->getKey());
                $this->assertInvoiceDoesNotExist($currentOrder);
                $this->assertSeries($currentSeries);

                $prepared = $this->preparation->forCreation($currentOrder, $currentSeries, $context);
                $conversionContext = $this->currencyConversion->contextFor($prepared);
                $rate = $this->currencyConversion->fetchRate($conversionContext);

                try {
                    return $this->persist(
                        $currentOrder,
                        $currentSeries,
                        $context,
                        $conversionContext,
                        $rate,
                    );
                } catch (InvoiceDomainException $exception) {
                    if ($exception->errorCode() !== 'invoice_exchange_rate_context_changed'
                        || $attempt === self::MAX_CONTEXT_ATTEMPTS) {
                        throw $exception;
                    }
                }
            }

            throw $this->currencyConversion->contextChanged();
        } catch (QueryException $exception) {
            throw $this->mapQueryException($order, $exception);
        }
    }

    private function persist(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $operationContext,
        InvoiceCurrencyConversionContext $expectedConversionContext,
        ?NbpExchangeRate $rate,
    ): Invoice {
        return DB::transaction(function () use (
            $order,
            $series,
            $operationContext,
            $expectedConversionContext,
            $rate,
        ): Invoice {
            $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $managedSeries = InvoiceSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $this->assertInvoiceDoesNotExist($managedOrder);
            $this->assertSeries($managedSeries);

            $prepared = $this->preparation->forCreation($managedOrder, $managedSeries, $operationContext);
            $actualConversionContext = $this->currencyConversion->contextFor($prepared);
            if (! $expectedConversionContext->equals($actualConversionContext)) {
                throw $this->currencyConversion->contextChanged();
            }

            $prepared = $this->currencyConversion->apply($prepared, $actualConversionContext, $rate);
            $relatedProforma = $this->relatedProforma($managedOrder);
            $slot = OrderDocumentSlot::query()->create([
                'order_id' => $managedOrder->getKey(),
                'document_type' => InvoiceDocumentType::Invoice,
                'invoice_id' => null,
            ]);
            $invoice = Invoice::query()->create([
                'order_id' => $managedOrder->getKey(),
                'invoice_series_id' => $managedSeries->getKey(),
                'document_type' => InvoiceDocumentType::Invoice,
                'status' => InvoiceDocumentStatus::Draft,
                'lock_version' => 1,
            ]);
            $invoiceAttributes = $this->withRelatedProformaSnapshot(
                $prepared->invoiceAttributes,
                $relatedProforma,
            );
            $invoice->fill($invoiceAttributes);
            $invoice->save();

            $invoice->items()->createMany($prepared->itemAttributes);
            $this->ksefSemanticSnapshot->initialize($invoice);
            $invoice = $this->assignNumber($invoice, $prepared->invoiceAttributes['issue_date']);
            $this->afterNumberAssigned($invoice);
            $invoice->status = InvoiceDocumentStatus::Issued;
            $invoice->save();
            $slot->update(['invoice_id' => $invoice->getKey()]);

            $supersededProforma = $this->supersedeProforma($relatedProforma, $invoice, $operationContext);
            $this->createOrderEvent(
                $managedOrder,
                $invoice,
                $managedSeries,
                $operationContext,
                $supersededProforma,
            );
            $this->automaticKsefSubmissions->dispatchIfEligible($invoice);

            return $invoice->refresh()->load('items');
        }, 3);
    }

    /** Test seam proving that the outer transaction also owns numbering. */
    protected function afterNumberAssigned(Invoice $invoice): void {}

    private function assertInvoiceDoesNotExist(Order $order): void
    {
        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->first();
        $slot = OrderDocumentSlot::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->first();

        if ($invoice !== null) {
            if ($slot === null || $slot->invoice_id !== $invoice->getKey()) {
                Log::error('Wykryto Fakturę VAT bez poprawnego slotu dokumentu.', [
                    'order_id' => $order->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'slot_id' => $slot?->getKey(),
                    'slot_invoice_id' => $slot?->invoice_id,
                ]);
            }

            throw $this->alreadyExists();
        }

        if ($slot !== null) {
            $this->logInconsistentSlot($slot);
            throw $this->inconsistentSlot();
        }
    }

    private function assertSeries(InvoiceSeries $series): void
    {
        if (! $series->is_active) {
            throw new InvoiceDomainException(
                'invoice_series_inactive',
                'Nie można wystawić dokumentu, ponieważ wybrana seria numeracji jest ukryta.',
            );
        }

        if ($series->document_type !== InvoiceDocumentType::Invoice) {
            throw new InvoiceDomainException(
                'invoice_series_type_mismatch',
                'Wybrana seria numeracji nie obsługuje tego typu dokumentu.',
            );
        }
    }

    private function assignNumber(Invoice $invoice, string $issueDate): Invoice
    {
        try {
            return $this->numbering->assignNextNumber(
                $invoice,
                CarbonImmutable::parse($issueDate, config('app.timezone')),
            );
        } catch (DomainException|QueryException $exception) {
            throw new InvoiceDomainException(
                'invoice_numbering_failed',
                'Nie udało się nadać numeru dokumentu.',
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    private function relatedProforma(Order $order): ?Invoice
    {
        return Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Proforma)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withRelatedProformaSnapshot(array $attributes, ?Invoice $proforma): array
    {
        if ($proforma === null || $proforma->number === null) {
            return $attributes;
        }

        $orderSnapshot = $attributes['order_snapshot'] ?? [];
        $orderSnapshot['related_documents']['proforma'] = [
            'invoice_id' => $proforma->getKey(),
            'number' => $proforma->number,
            'issue_date' => $proforma->issue_date?->toDateString(),
        ];
        $attributes['order_snapshot'] = $orderSnapshot;

        return $attributes;
    }

    private function supersedeProforma(
        ?Invoice $proforma,
        Invoice $invoice,
        InvoiceOperationContext $context,
    ): ?Invoice {

        if ($proforma === null || $proforma->isProformaSuperseded()) {
            return $proforma;
        }

        $proforma->update([
            'proforma_superseded_at' => $context->occurredAt,
            'superseded_by_invoice_id' => $invoice->getKey(),
        ]);

        return $proforma;
    }

    private function createOrderEvent(
        Order $order,
        Invoice $invoice,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
        ?Invoice $supersededProforma,
    ): void {
        $payload = [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'invoice_series_id' => $series->getKey(),
            'invoice_series_name' => $series->name,
            'total_gross' => $invoice->total_gross,
            'currency' => $invoice->currency,
            'source' => $context->source->value,
        ];

        if ($supersededProforma !== null) {
            $payload['superseded_proforma_id'] = $supersededProforma->getKey();
            $payload['superseded_proforma_number'] = $supersededProforma->number;
        }

        $event = $order->events()->make([
            'event_type' => 'invoice_issued',
            'title' => 'Wystawiono fakturę',
            'description' => 'Wystawiono fakturę VAT do zamówienia.',
            'payload' => $payload,
        ]);
        $event->created_at = $context->occurredAt;
        $event->updated_at = $context->occurredAt;
        $event->save();
    }

    private function mapQueryException(Order $order, QueryException $exception): InvoiceDomainException
    {
        if (! $this->isUniqueConstraintViolation($exception)) {
            throw $exception;
        }

        if (Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->exists()) {
            return $this->alreadyExists($exception);
        }

        Log::warning('Konflikt równoległego tworzenia slotu Faktury VAT.', [
            'order_id' => $order->getKey(),
            'error' => $exception->getMessage(),
        ]);

        return new InvoiceDomainException(
            'invoice_document_slot_conflict',
            'Nie można utworzyć dokumentu z powodu konfliktu operacji dla tego zamówienia.',
            [],
            $exception,
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    private function logInconsistentSlot(OrderDocumentSlot $slot): void
    {
        Log::error('Wykryto niespójny slot dokumentu.', [
            'slot_id' => $slot->getKey(),
            'order_id' => $slot->order_id,
            'document_type' => $slot->document_type->value,
            'invoice_id' => $slot->invoice_id,
        ]);
    }

    private function alreadyExists(?\Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_already_exists',
            'Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.',
            [],
            $previous,
        );
    }

    private function inconsistentSlot(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_document_slot_inconsistent',
            'Nie można utworzyć dokumentu, ponieważ wykryto niespójne powiązanie dokumentu z zamówieniem.',
        );
    }
}
