<?php

namespace Modules\Automation\Services;

use Carbon\CarbonImmutable;
use Modules\Automation\Models\AutomationRun;
use Modules\Invoices\Enums\InvoiceOperationSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class AutomationInvoiceActionService
{
    public function __construct(private readonly InvoiceIssuingService $issuing) {}

    public function execute(AutomationRun $run, array $configuration): array
    {
        $seriesId = filter_var(
            $configuration['invoice_series_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($seriesId === false) {
            throw new InvoiceDomainException(
                'automation_invoice_series_invalid',
                'Automatyczna akcja nie ma wybranej prawidłowej serii numeracji Faktur VAT.',
            );
        }

        $series = InvoiceSeries::query()->find($seriesId);

        if ($series === null) {
            throw new InvoiceDomainException(
                'automation_invoice_series_missing',
                'Seria numeracji wybrana w automatycznej akcji już nie istnieje.',
            );
        }

        $context = new InvoiceOperationContext(
            source: InvoiceOperationSource::Automation,
            actorSnapshot: array_filter([
                'type' => 'automation',
                'automation_run_id' => $run->getKey(),
                'automation_rule_id' => $run->automation_rule_id,
                'automation_rule_name' => data_get($run->rule_snapshot, 'name'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            occurredAt: CarbonImmutable::now(config('app.timezone')),
        );
        $invoice = $this->issuing->issue($run->order, $series, $context);

        return [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'invoice_series_id' => $series->getKey(),
            'source' => InvoiceOperationSource::Automation->value,
        ];
    }
}
