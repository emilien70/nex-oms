<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class InvoiceDateResolver
{
    /**
     * @return array{issue_date: string, sale_date: string, payment_due_date: ?string, issued_at: CarbonImmutable}
     */
    public function forCreation(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
    ): array {
        $issueDate = $context->occurredAt
            ->setTimezone((string) config('app.timezone'))
            ->startOfDay();

        return $this->resolve($order, $series, $issueDate, $context->occurredAt);
    }

    /**
     * @return array{issue_date: string, sale_date: string, payment_due_date: ?string, issued_at: CarbonImmutable}
     */
    public function forRefresh(
        Order $order,
        InvoiceSeries $series,
        Invoice $invoice,
    ): array {
        $issueDate = CarbonImmutable::parse($invoice->issue_date->toDateString(), config('app.timezone'));
        $issuedAt = CarbonImmutable::instance($invoice->issued_at);

        return $this->resolve($order, $series, $issueDate, $issuedAt);
    }

    /**
     * @return array{issue_date: string, sale_date: string, payment_due_date: ?string, issued_at: CarbonImmutable}
     */
    private function resolve(
        Order $order,
        InvoiceSeries $series,
        CarbonImmutable $issueDate,
        CarbonImmutable $issuedAt,
    ): array {
        $saleDate = match ($series->sale_date_source) {
            InvoiceSaleDateSource::OrderDate => $order->purchased_at !== null
                ? CarbonImmutable::instance($order->purchased_at)->toDateString()
                : $issueDate->toDateString(),
            InvoiceSaleDateSource::PaymentOrIssue => $order->paid_at !== null
                ? CarbonImmutable::instance($order->paid_at)->toDateString()
                : $issueDate->toDateString(),
            InvoiceSaleDateSource::IssueDate => $issueDate->toDateString(),
        };

        $paymentDueDate = match ($series->payment_due_mode) {
            InvoicePaymentDueMode::None, InvoicePaymentDueMode::Order => null,
            InvoicePaymentDueMode::DaysFromIssue => $issueDate
                ->addDays((int) ($series->payment_due_days ?? 0))
                ->toDateString(),
        };

        return [
            'issue_date' => $issueDate->toDateString(),
            'sale_date' => $saleDate,
            'payment_due_date' => $paymentDueDate,
            'issued_at' => $issuedAt,
        ];
    }
}
