<?php

namespace Modules\Automation\Services;

use Illuminate\Support\Collection;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Integrations\NexPrinter\Services\NexPrintJobService;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Shipments\Models\Shipment;
use Ramsey\Uuid\Uuid;

class AutomationNexPrinterActionService
{
    public function __construct(private readonly NexPrintJobService $printJobs) {}

    public function execute(
        AutomationRun $run,
        AutomationRunStep $step,
        array $configuration,
    ): array {
        $documentType = (string) ($configuration['document_type'] ?? '');

        if (! in_array($documentType, [
            AutomationCatalog::PRINT_DOCUMENT_INVOICE,
            AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL,
            AutomationCatalog::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS,
        ], true)) {
            throw new NexPrinterException(
                'document_type_invalid',
                'Automatyczny wydruk nie obsługuje wybranego dokumentu.',
            );
        }

        $printerId = filter_var(
            $configuration['printer_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $printer = $printerId === false ? null : NexPrinter::query()->find($printerId);

        if (! $printer instanceof NexPrinter) {
            throw new NexPrinterException(
                'printer_missing',
                'Drukarka wybrana w automatycznej akcji już nie istnieje.',
            );
        }

        return match ($documentType) {
            AutomationCatalog::PRINT_DOCUMENT_INVOICE => $this->executeInvoice($run, $step, $printer),
            AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL => $this->executeShipmentLabels(
                $run,
                $step,
                $printer,
                latestOnly: true,
            ),
            AutomationCatalog::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS => $this->executeShipmentLabels(
                $run,
                $step,
                $printer,
                latestOnly: false,
            ),
        };
    }

    private function executeInvoice(
        AutomationRun $run,
        AutomationRunStep $step,
        NexPrinter $printer,
    ): array {
        $invoice = $this->resolveInvoice($run, $step);
        $job = $this->printJobs->queueInvoice(
            $invoice,
            $printer,
            Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "nex-oms:automation-print:run:{$run->getKey()}:position:{$step->position}:invoice:{$invoice->getKey()}:printer:{$printer->getKey()}",
            )->toString(),
            NexPrintJob::ORIGIN_AUTOMATION,
        );

        return [
            'print_job_id' => $job->getKey(),
            'print_job_public_id' => $job->public_id,
            'status' => $job->status->value,
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'printer_id' => $printer->getKey(),
            'printer_name' => $job->printer_name_snapshot,
        ];
    }

    private function executeShipmentLabels(
        AutomationRun $run,
        AutomationRunStep $step,
        NexPrinter $printer,
        bool $latestOnly,
    ): array {
        $shipments = $this->resolveShipmentLabels($run, $latestOnly);
        $jobs = $shipments->map(fn (Shipment $shipment) => $this->printJobs->queueShipmentLabel(
            $shipment,
            $printer,
            Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "nex-oms:automation-print:run:{$run->getKey()}:position:{$step->position}:shipment:{$shipment->getKey()}:printer:{$printer->getKey()}",
            )->toString(),
            NexPrintJob::ORIGIN_AUTOMATION,
        ));
        $firstJob = $jobs->first();

        return [
            'print_job_id' => $firstJob->getKey(),
            'print_job_ids' => $jobs->map->getKey()->all(),
            'print_job_public_id' => $firstJob->public_id,
            'print_job_public_ids' => $jobs->pluck('public_id')->all(),
            'status' => $firstJob->status->value,
            'statuses' => $jobs->map(fn (NexPrintJob $job): string => $job->status->value)->all(),
            'shipment_id' => $shipments->first()->getKey(),
            'shipment_ids' => $shipments->map->getKey()->all(),
            'tracking_number' => $shipments->first()->tracking_number,
            'tracking_numbers' => $shipments->pluck('tracking_number')->all(),
            'document_count' => $jobs->count(),
            'printer_id' => $printer->getKey(),
            'printer_name' => $firstJob->printer_name_snapshot,
        ];
    }

    /** @return Collection<int, Shipment> */
    private function resolveShipmentLabels(AutomationRun $run, bool $latestOnly): Collection
    {
        $shipments = Shipment::query()
            ->where('order_id', $run->order_id)
            ->whereNotNull('tracking_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Shipment $shipment): bool => (
                $shipment->canDownloadLabel()
                && strtolower((string) $shipment->label_format) === 'pdf'
            ))
            ->values();

        if ($shipments->isEmpty()) {
            throw new NexPrinterException(
                'shipment_label_missing',
                'Nie znaleziono dostępnej etykiety PDF do wydruku.',
            );
        }

        return $latestOnly ? collect([$shipments->last()]) : $shipments;
    }

    private function resolveInvoice(AutomationRun $run, AutomationRunStep $step): Invoice
    {
        $previousInvoiceId = $run->steps()
            ->where('position', '<', $step->position)
            ->where('action_type', AutomationCatalog::ACTION_ISSUE_INVOICE)
            ->where('status', 'completed')
            ->orderByDesc('position')
            ->get()
            ->map(fn (AutomationRunStep $previousStep): mixed => data_get($previousStep->output, 'invoice_id'))
            ->first(fn (mixed $invoiceId): bool => filter_var(
                $invoiceId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            ) !== false);
        $eventInvoiceId = data_get($run->event_payload, 'invoice_id');
        $candidateId = $previousInvoiceId ?? $eventInvoiceId;

        if ($candidateId !== null) {
            $invoiceId = filter_var(
                $candidateId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $invoice = $invoiceId === false ? null : Invoice::query()->find($invoiceId);
        } else {
            $invoice = Invoice::query()
                ->where('order_id', $run->order_id)
                ->where('document_type', InvoiceDocumentType::Invoice->value)
                ->where('status', InvoiceDocumentStatus::Issued->value)
                ->latest('id')
                ->first();
        }

        if (! $invoice instanceof Invoice
            || $invoice->order_id !== $run->order_id
            || ! $invoice->isInvoice()
            || ! $invoice->isIssued()) {
            throw new NexPrinterException(
                'invoice_missing',
                'Nie znaleziono wystawionej Faktury VAT do wydruku.',
            );
        }

        return $invoice;
    }
}
