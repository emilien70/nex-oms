<?php

namespace Modules\Integrations\NexPrinter\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Integrations\NexPrinter\Enums\NexPrintJobStatus;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfService;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use RuntimeException;
use Throwable;

class NexPrintJobService
{
    private const MAX_PDF_SIZE = 25 * 1024 * 1024;

    private const DOCUMENT_TYPE_SHIPMENT_LABEL = 'shipment_label';

    public function __construct(
        private readonly InvoicePdfService $pdfs,
        private readonly InvoicePdfFilenameGenerator $filenames,
        private readonly CourierDriverRegistry $courierDrivers,
    ) {}

    public function queueInvoice(
        Invoice $invoice,
        NexPrinter $printer,
        string $idempotencyKey,
        string $origin = NexPrintJob::ORIGIN_MANUAL,
    ): NexPrintJob {
        $this->assertOrigin($origin);

        $existing = NexPrintJob::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if (
                $existing->invoice_id !== $invoice->getKey()
                || $existing->printer_id !== $printer->getKey()
                || $existing->copies !== 1
                || $existing->origin !== $origin
            ) {
                throw new NexPrinterException(
                    'idempotency_conflict',
                    'Identyfikator żądania wydruku został już wykorzystany.',
                );
            }

            return $existing;
        }

        if (
            ! $invoice->isIssued()
            || ! in_array($invoice->document_type, [
                InvoiceDocumentType::Invoice,
                InvoiceDocumentType::Correction,
            ], true)
        ) {
            throw new NexPrinterException(
                'document_not_printable',
                'NEX Printer może drukować wystawione Faktury VAT i Korekty.',
            );
        }

        $this->assertPrinterSupportsPdf($printer);
        $contents = $this->pdfs->contents($invoice);

        return $this->storePdfJob(
            orderId: $invoice->order_id,
            invoiceId: $invoice->getKey(),
            documentType: $invoice->document_type->value,
            documentNumber: $invoice->number,
            filename: $this->filenames->downloadName($invoice),
            contents: $contents,
            printer: $printer,
            idempotencyKey: $idempotencyKey,
            origin: $origin,
        );
    }

    public function queueShipmentLabel(
        Shipment $shipment,
        NexPrinter $printer,
        string $idempotencyKey,
        string $origin = NexPrintJob::ORIGIN_MANUAL,
    ): NexPrintJob {
        $this->assertOrigin($origin);

        $existing = NexPrintJob::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if (
                $existing->order_id !== $shipment->order_id
                || $existing->invoice_id !== null
                || $existing->document_type !== self::DOCUMENT_TYPE_SHIPMENT_LABEL
                || $existing->document_number !== $shipment->tracking_number
                || $existing->printer_id !== $printer->getKey()
                || $existing->copies !== 1
                || $existing->origin !== $origin
            ) {
                throw new NexPrinterException(
                    'idempotency_conflict',
                    'Identyfikator żądania wydruku został już wykorzystany.',
                );
            }

            return $existing;
        }

        if (! $shipment->canDownloadLabel()) {
            throw new NexPrinterException(
                'shipment_label_unavailable',
                'Etykieta nie jest jeszcze dostępna do wydruku.',
            );
        }

        if (strtolower((string) $shipment->label_format) !== 'pdf') {
            throw new NexPrinterException(
                'shipment_label_not_pdf',
                'NEX Printer może drukować etykiety kurierskie zapisane jako PDF.',
            );
        }

        $this->assertPrinterSupportsPdf($printer);

        try {
            $contents = $this->courierDrivers->forShipment($shipment)->label($shipment)->body();
        } catch (DomainException|RuntimeException $exception) {
            throw new NexPrinterException(
                'shipment_label_unavailable',
                $exception->getMessage(),
                $exception,
            );
        }

        $filename = str_replace('_', '-', $shipment->provider)
            .'-'.$shipment->tracking_number.'.pdf';

        return $this->storePdfJob(
            orderId: $shipment->order_id,
            invoiceId: null,
            documentType: self::DOCUMENT_TYPE_SHIPMENT_LABEL,
            documentNumber: $shipment->tracking_number,
            filename: $filename,
            contents: $contents,
            printer: $printer,
            idempotencyKey: $idempotencyKey,
            origin: $origin,
        );
    }

    private function assertOrigin(string $origin): void
    {
        if (! in_array($origin, [NexPrintJob::ORIGIN_MANUAL, NexPrintJob::ORIGIN_AUTOMATION], true)) {
            throw new NexPrinterException('origin_invalid', 'Nieprawidłowe źródło zlecenia wydruku.');
        }
    }

    private function assertPrinterSupportsPdf(NexPrinter $printer): void
    {
        $printer->refresh();
        $printer->loadMissing('agent');
        $formats = data_get($printer->capabilities, 'formats', []);
        $supportsPdf = is_array($formats)
            && in_array('pdf', array_map(
                static fn ($format): string => strtolower((string) $format),
                $formats,
            ), true);

        if (
            ! $printer->is_enabled
            || ! $printer->is_available
            || $printer->agent === null
            || ! $printer->agent->is_active
            || $printer->agent->revoked_at !== null
            || ! $supportsPdf
        ) {
            throw new NexPrinterException(
                'printer_unavailable',
                'Wybrana drukarka nie jest aktywna albo nie obsługuje dokumentów PDF.',
            );
        }
    }

    private function storePdfJob(
        ?int $orderId,
        ?int $invoiceId,
        string $documentType,
        ?string $documentNumber,
        string $filename,
        string $contents,
        NexPrinter $printer,
        string $idempotencyKey,
        string $origin,
    ): NexPrintJob {
        $size = strlen($contents);

        if (! str_starts_with($contents, '%PDF-') || $size === 0 || $size > self::MAX_PDF_SIZE) {
            throw new NexPrinterException(
                'invalid_pdf_payload',
                'Dokument PDF nie może zostać przekazany do NEX Printer.',
            );
        }

        $publicId = (string) Str::uuid();
        $path = 'nex-printer/jobs/'.$publicId.'.pdf';
        $disk = Storage::disk('local');

        if (! $disk->put($path, $contents)) {
            throw new NexPrinterException(
                'payload_store_failed',
                'Nie udało się bezpiecznie zapisać pliku do wydruku.',
            );
        }

        try {
            return DB::transaction(function () use (
                $orderId,
                $invoiceId,
                $documentType,
                $documentNumber,
                $filename,
                $contents,
                $size,
                $printer,
                $idempotencyKey,
                $origin,
                $publicId,
                $path,
            ): NexPrintJob {
                $job = NexPrintJob::query()->create([
                    'public_id' => $publicId,
                    'agent_id' => $printer->agent_id,
                    'printer_id' => $printer->getKey(),
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'document_type' => $documentType,
                    'document_number' => $documentNumber,
                    'printer_name_snapshot' => $printer->display_name,
                    'payload_disk' => 'local',
                    'payload_path' => $path,
                    'payload_filename' => $filename,
                    'content_type' => 'application/pdf',
                    'payload_sha256' => hash('sha256', $contents),
                    'payload_size' => $size,
                    'copies' => 1,
                    'status' => NexPrintJobStatus::Queued,
                    'origin' => $origin,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addDays(7),
                ]);

                $this->recordEvent($job, 'queued', NexPrintJobStatus::Queued, 'oms', [
                    'printer_id' => $printer->getKey(),
                ]);

                return $job;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    /** @return array{job: NexPrintJob, lease_token: string}|null */
    public function claim(NexPrinterAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent): ?array {
            $this->expireQueuedJobs($agent);
            $this->releaseExpiredClaims($agent);

            $job = NexPrintJob::query()
                ->where('agent_id', $agent->getKey())
                ->where('status', NexPrintJobStatus::Queued->value)
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $plainLeaseToken = bin2hex(random_bytes(24));
            $updated = NexPrintJob::query()
                ->whereKey($job->getKey())
                ->where('status', NexPrintJobStatus::Queued->value)
                ->update([
                    'status' => NexPrintJobStatus::Claimed->value,
                    'lease_token_hash' => hash('sha256', $plainLeaseToken),
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $job->refresh();
            $this->recordEvent($job, 'claimed', NexPrintJobStatus::Claimed, 'agent');

            return ['job' => $job, 'lease_token' => $plainLeaseToken];
        });
    }

    public function payload(NexPrintJob $job, NexPrinterAgent $agent, string $plainLeaseToken): string
    {
        $this->assertLease($job, $agent, $plainLeaseToken);
        $contents = Storage::disk($job->payload_disk)->get($job->payload_path);

        if (
            ! is_string($contents)
            || ! str_starts_with($contents, '%PDF-')
            || ! hash_equals($job->payload_sha256, hash('sha256', $contents))
        ) {
            throw new NexPrinterException(
                'payload_unavailable',
                'Plik zadania wydruku jest niedostępny albo uszkodzony.',
            );
        }

        if ($job->status === NexPrintJobStatus::Claimed) {
            $job->forceFill([
                'status' => NexPrintJobStatus::Downloaded,
                'downloaded_at' => now(),
                'lease_expires_at' => now()->addMinutes(5),
            ])->save();
            $this->recordEvent($job, 'payload_downloaded', NexPrintJobStatus::Downloaded, 'agent');
        } else {
            $job->forceFill([
                'lease_expires_at' => now()->addMinutes(5),
            ])->save();
        }

        return $contents;
    }

    public function complete(
        NexPrintJob $job,
        NexPrinterAgent $agent,
        string $plainLeaseToken,
        NexPrintJobStatus $result,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): NexPrintJob {
        if (! in_array($result, [NexPrintJobStatus::Spooled, NexPrintJobStatus::Failed], true)) {
            throw new NexPrinterException('invalid_result', 'Nieprawidłowy wynik zadania wydruku.');
        }

        return DB::transaction(function () use (
            $job,
            $agent,
            $plainLeaseToken,
            $result,
            $errorCode,
            $errorMessage,
        ): NexPrintJob {
            $job->refresh();

            if ($job->agent_id !== $agent->getKey()) {
                throw new NexPrinterException(
                    'job_agent_mismatch',
                    'Zadanie wydruku należy do innego programu NEX Printer.',
                );
            }

            if ($job->status === $result) {
                return $job;
            }

            $this->assertLease($job, $agent, $plainLeaseToken);
            $now = now();
            $job->forceFill([
                'status' => $result,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'spooled_at' => $result === NexPrintJobStatus::Spooled ? $now : null,
                'failed_at' => $result === NexPrintJobStatus::Failed ? $now : null,
                'last_error_code' => $result === NexPrintJobStatus::Failed ? $errorCode : null,
                'last_error_message' => $result === NexPrintJobStatus::Failed ? $errorMessage : null,
            ])->save();

            $this->recordEvent(
                $job,
                $result === NexPrintJobStatus::Spooled ? 'spooled' : 'failed',
                $result,
                'agent',
                $result === NexPrintJobStatus::Failed
                    ? ['error_code' => $errorCode, 'error_message' => $errorMessage]
                    : null,
            );

            return $job;
        });
    }

    private function assertLease(
        NexPrintJob $job,
        NexPrinterAgent $agent,
        string $plainLeaseToken,
    ): void {
        if (
            $job->agent_id !== $agent->getKey()
            || $job->lease_token_hash === null
            || $job->lease_expires_at === null
            || $job->lease_expires_at->isPast()
            || ! in_array($job->status, [
                NexPrintJobStatus::Claimed,
                NexPrintJobStatus::Downloaded,
            ], true)
            || ! hash_equals($job->lease_token_hash, hash('sha256', $plainLeaseToken))
        ) {
            throw new NexPrinterException(
                'invalid_job_lease',
                'Dostęp do zadania wydruku wygasł albo jest nieprawidłowy.',
            );
        }
    }

    private function releaseExpiredClaims(NexPrinterAgent $agent): void
    {
        NexPrintJob::query()
            ->where('agent_id', $agent->getKey())
            ->whereIn('status', [
                NexPrintJobStatus::Claimed->value,
                NexPrintJobStatus::Downloaded->value,
            ])
            ->where('lease_expires_at', '<=', now())
            ->get()
            ->each(function (NexPrintJob $job): void {
                $job->forceFill([
                    'status' => NexPrintJobStatus::Queued,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                ])->save();
                $this->recordEvent($job, 'lease_expired', NexPrintJobStatus::Queued, 'oms');
            });
    }

    private function expireQueuedJobs(NexPrinterAgent $agent): void
    {
        NexPrintJob::query()
            ->where('agent_id', $agent->getKey())
            ->where('status', NexPrintJobStatus::Queued->value)
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (NexPrintJob $job): void {
                $job->forceFill(['status' => NexPrintJobStatus::Expired])->save();
                $this->recordEvent($job, 'expired', NexPrintJobStatus::Expired, 'oms');
            });
    }

    /** @param array<string, mixed>|null $context */
    private function recordEvent(
        NexPrintJob $job,
        string $eventType,
        NexPrintJobStatus $status,
        string $actor,
        ?array $context = null,
    ): void {
        $job->events()->create([
            'event_type' => $eventType,
            'status' => $status,
            'actor' => $actor,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
