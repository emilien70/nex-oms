<?php

namespace Modules\Invoices\Services;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceNumberCounterAdjustment;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceNumberPreview;

class InvoiceNumberingService
{
    private const MAX_ASSIGNMENT_ATTEMPTS = 3;

    public function __construct(
        private readonly InvoiceNumberingPeriodResolver $periodResolver,
        private readonly InvoiceNumberFormatter $formatter,
        private readonly InvoiceNumberingConfigurationValidator $configurationValidator,
    ) {}

    public function previewNextNumber(
        InvoiceSeries $series,
        CarbonInterface $numberingDate,
        ?int $candidateNextSequenceNumber = null,
    ): InvoiceNumberPreview {
        $this->configurationValidator->validateSeries($series);
        $periodKey = $this->periodResolver->resolve($series, $numberingDate);
        $counter = InvoiceNumberCounter::query()
            ->where('invoice_series_id', $series->getKey())
            ->where('numbering_period_key', $periodKey)
            ->first();
        $currentLast = $counter?->last_sequence_number ?? 0;
        $currentNext = $currentLast + 1;
        $previewSequence = $candidateNextSequenceNumber ?? $currentNext;

        if ($previewSequence < 1) {
            throw new DomainException('Nowy następny numer musi być większy lub równy 1.');
        }

        return new InvoiceNumberPreview(
            numberingPeriodKey: $periodKey,
            currentLastSequenceNumber: $currentLast,
            protectedFloorSequenceNumber: $counter?->protected_floor_sequence_number ?? 0,
            currentNextSequenceNumber: $currentNext,
            previewSequenceNumber: $previewSequence,
            formattedNumber: $this->formatter->format($series->number_format, $previewSequence, $numberingDate),
            counterExists: $counter !== null,
        );
    }

    public function assignNextNumber(Invoice $invoice, CarbonInterface $numberingDate): Invoice
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ASSIGNMENT_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $numberingDate): Invoice {
                    $managedInvoice = Invoice::query()
                        ->lockForUpdate()
                        ->findOrFail($invoice->getKey());

                    $this->ensureInvoiceCanReceiveNumber($managedInvoice, $numberingDate);

                    $series = InvoiceSeries::query()
                        ->lockForUpdate()
                        ->findOrFail($managedInvoice->invoice_series_id);

                    if (! $series->is_active) {
                        throw new DomainException('Nieaktywna seria numeracji nie może nadać numeru dokumentowi.');
                    }

                    if ($managedInvoice->document_type !== $series->document_type) {
                        throw new DomainException('Typ dokumentu nie odpowiada typowi wybranej serii numeracji.');
                    }

                    $this->configurationValidator->validateSeries($series);
                    $periodKey = $this->periodResolver->resolve($series, $numberingDate);
                    $counter = $this->lockedOrCreatedCounter($series, $periodKey);
                    $nextSequence = $counter->nextSequenceNumber();
                    $formattedNumber = $this->formatter->format(
                        $series->number_format,
                        $nextSequence,
                        $numberingDate,
                    );

                    if (Invoice::query()
                        ->where('invoice_series_id', $series->getKey())
                        ->where('numbering_period_key', $periodKey)
                        ->where('sequence_number', $nextSequence)
                        ->exists()) {
                        throw new DomainException('Nie można nadać numeru, ponieważ techniczny numer kolejny jest już zajęty.');
                    }

                    if (Invoice::query()
                        ->where('invoice_series_id', $series->getKey())
                        ->where('number', $formattedNumber)
                        ->exists()) {
                        throw new DomainException('Nie można nadać numeru, ponieważ sformatowany numer dokumentu jest już zajęty w tej serii.');
                    }

                    $counter->last_sequence_number = $nextSequence;
                    $counter->save();

                    $managedInvoice->number = $formattedNumber;
                    $managedInvoice->sequence_number = $nextSequence;
                    $managedInvoice->numbering_period_key = $periodKey;
                    $managedInvoice->number_format_snapshot = $series->number_format;
                    $managedInvoice->series_name_snapshot = $series->name;
                    $managedInvoice->issue_date ??= $numberingDate->toDateString();
                    $managedInvoice->save();

                    return $managedInvoice->refresh();
                }, 3);
            } catch (QueryException $exception) {
                if (! $this->isRetryableNumberingConflict($exception)) {
                    throw $exception;
                }

                $lastException = $exception;

                if ($attempt < self::MAX_ASSIGNMENT_ATTEMPTS) {
                    usleep(20_000 * $attempt);

                    continue;
                }
            }
        }

        Log::warning('Nie udało się nadać numeru dokumentu po ponowieniach.', [
            'invoice_id' => $invoice->getKey(),
            'attempts' => self::MAX_ASSIGNMENT_ATTEMPTS,
            'error' => $lastException?->getMessage(),
        ]);

        throw new DomainException('Nie udało się nadać numeru z powodu konfliktu współbieżności. Spróbuj ponownie.');
    }

    /**
     * @param  array<string, mixed>|null  $actorSnapshot
     */
    public function setNextNumber(
        InvoiceSeries $series,
        CarbonInterface $periodDate,
        int $requestedNextSequenceNumber,
        string $reason,
        ?array $actorSnapshot = null,
    ): InvoiceNumberCounterAdjustment {
        $reason = trim($reason);

        if ($requestedNextSequenceNumber < 1) {
            throw new DomainException('Nowy następny numer musi być większy lub równy 1.');
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw new DomainException('Powód zmiany musi zawierać od 3 do 1000 znaków.');
        }

        return DB::transaction(function () use (
            $series,
            $periodDate,
            $requestedNextSequenceNumber,
            $reason,
            $actorSnapshot,
        ): InvoiceNumberCounterAdjustment {
            $managedSeries = InvoiceSeries::query()
                ->lockForUpdate()
                ->findOrFail($series->getKey());
            $this->configurationValidator->validateSeries($managedSeries);
            $periodKey = $this->periodResolver->resolve($managedSeries, $periodDate);
            $counter = InvoiceNumberCounter::query()
                ->where('invoice_series_id', $managedSeries->getKey())
                ->where('numbering_period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($counter === null && $requestedNextSequenceNumber === 1) {
                throw new DomainException('Podany numer jest już ustawiony jako następny numer tej serii i okresu.');
            }

            $counter ??= $this->lockedOrCreatedCounter($managedSeries, $periodKey);

            $previousLast = $counter->last_sequence_number;
            $previousFloor = $counter->protected_floor_sequence_number;
            $previousNext = $previousLast + 1;
            $highestExisting = (int) (Invoice::query()
                ->where('invoice_series_id', $managedSeries->getKey())
                ->where('numbering_period_key', $periodKey)
                ->whereNotNull('sequence_number')
                ->max('sequence_number') ?? 0);

            if ($highestExisting > $previousLast) {
                Log::error('Niespójność licznika numeracji z dokumentami.', [
                    'invoice_series_id' => $managedSeries->getKey(),
                    'numbering_period_key' => $periodKey,
                    'last_sequence_number' => $previousLast,
                    'highest_existing_sequence_number' => $highestExisting,
                ]);

                throw new DomainException('Nie można zmienić następnego numeru, ponieważ wykryto niespójność licznika z istniejącymi dokumentami.');
            }

            if ($requestedNextSequenceNumber === $previousNext) {
                throw new DomainException('Podany numer jest już ustawiony jako następny numer tej serii i okresu.');
            }

            if ($highestExisting > 0 && $requestedNextSequenceNumber < $previousNext) {
                throw new DomainException('Nie można cofnąć numeracji w okresie, w którym istnieją już dokumenty.');
            }

            $newLast = $requestedNextSequenceNumber - 1;
            $newFloor = $highestExisting === 0
                ? $newLast
                : max($previousFloor, $newLast);

            if ($newFloor > $newLast) {
                throw new DomainException('Chroniony próg numeracji nie może być wyższy od ostatniego numeru kolejnego.');
            }

            $counter->last_sequence_number = $newLast;
            $counter->protected_floor_sequence_number = $newFloor;
            $counter->save();

            return $counter->adjustments()->create([
                'numbering_period_key_snapshot' => $periodKey,
                'series_name_snapshot' => $managedSeries->name,
                'number_format_snapshot' => $managedSeries->number_format,
                'previous_last_sequence_number' => $previousLast,
                'new_last_sequence_number' => $newLast,
                'previous_protected_floor_sequence_number' => $previousFloor,
                'new_protected_floor_sequence_number' => $newFloor,
                'previous_next_sequence_number' => $previousNext,
                'new_next_sequence_number' => $requestedNextSequenceNumber,
                'reason' => $reason,
                'actor_snapshot' => $actorSnapshot ?? [
                    'type' => 'application',
                    'name' => 'NEX-OMS',
                ],
                'metadata' => [
                    'highest_existing_sequence_number' => $highestExisting,
                    'source' => 'invoice_series_settings',
                ],
            ]);
        }, 3);
    }

    /**
     * Cofnięcie jest dozwolone wyłącznie dla wolnego końca numeracji.
     * Wewnętrzne luki oraz ręcznie chroniony próg pozostają nienaruszone.
     *
     * @param  array<string, mixed>|null  $actorSnapshot
     */
    public function releaseTailNumberAfterDeletion(
        Invoice $invoice,
        ?array $actorSnapshot = null,
    ): bool {
        if ($invoice->invoice_series_id === null
            || $invoice->numbering_period_key === null
            || $invoice->sequence_number === null) {
            throw new DomainException('Dokument nie posiada kompletnej tożsamości numeracji.');
        }

        $series = InvoiceSeries::query()
            ->lockForUpdate()
            ->findOrFail($invoice->invoice_series_id);
        $counter = InvoiceNumberCounter::query()
            ->where('invoice_series_id', $series->getKey())
            ->where('numbering_period_key', $invoice->numbering_period_key)
            ->lockForUpdate()
            ->first();

        if ($counter === null || $invoice->sequence_number > $counter->last_sequence_number) {
            throw new DomainException('Licznik numeracji jest niespójny z usuwanym dokumentem.');
        }

        $highestRemaining = (int) (Invoice::query()
            ->where('invoice_series_id', $series->getKey())
            ->where('numbering_period_key', $invoice->numbering_period_key)
            ->whereKeyNot($invoice->getKey())
            ->whereNotNull('sequence_number')
            ->max('sequence_number') ?? 0);

        if ($highestRemaining > $counter->last_sequence_number) {
            throw new DomainException('Licznik numeracji jest niespójny z istniejącymi dokumentami.');
        }

        if ($invoice->sequence_number !== $counter->last_sequence_number) {
            return false;
        }

        $previousLast = $counter->last_sequence_number;
        $previousFloor = $counter->protected_floor_sequence_number;
        $newLast = max($highestRemaining, $previousFloor);

        if ($newLast >= $previousLast) {
            return false;
        }

        $counter->last_sequence_number = $newLast;
        $counter->save();

        $counter->adjustments()->create([
            'numbering_period_key_snapshot' => $invoice->numbering_period_key,
            'series_name_snapshot' => $invoice->series_name_snapshot ?? $series->name,
            'number_format_snapshot' => $invoice->number_format_snapshot ?? $series->number_format,
            'previous_last_sequence_number' => $previousLast,
            'new_last_sequence_number' => $newLast,
            'previous_protected_floor_sequence_number' => $previousFloor,
            'new_protected_floor_sequence_number' => $previousFloor,
            'previous_next_sequence_number' => $previousLast + 1,
            'new_next_sequence_number' => $newLast + 1,
            'reason' => 'Automatyczne cofnięcie wolnego końca numeracji po usunięciu Faktury VAT.',
            'actor_snapshot' => $actorSnapshot ?? [
                'type' => 'application',
                'name' => 'NEX-OMS',
            ],
            'metadata' => [
                'source' => 'invoice_deletion',
                'deleted_invoice_id' => $invoice->getKey(),
                'deleted_invoice_number' => $invoice->number,
                'highest_remaining_sequence_number' => $highestRemaining,
            ],
        ]);

        return true;
    }

    private function ensureInvoiceCanReceiveNumber(Invoice $invoice, CarbonInterface $numberingDate): void
    {
        if ($invoice->status !== InvoiceDocumentStatus::Draft) {
            throw new DomainException('Numer można nadać wyłącznie dokumentowi w statusie szkicu.');
        }

        if ($invoice->invoice_series_id === null) {
            throw new DomainException('Dokument nie posiada serii numeracji.');
        }

        if ($invoice->number !== null || $invoice->sequence_number !== null || $invoice->numbering_period_key !== null) {
            throw new DomainException('Dokument posiada już nadany numer.');
        }

        if ($invoice->issue_date !== null && $invoice->issue_date->toDateString() !== $numberingDate->toDateString()) {
            throw new DomainException('Data wystawienia dokumentu nie odpowiada dacie używanej do numeracji.');
        }
    }

    private function lockedOrCreatedCounter(InvoiceSeries $series, string $periodKey): InvoiceNumberCounter
    {
        DB::table('invoice_number_counters')->insertOrIgnore([
            'invoice_series_id' => $series->getKey(),
            'numbering_period_key' => $periodKey,
            'last_sequence_number' => 0,
            'protected_floor_sequence_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InvoiceNumberCounter::query()
            ->where('invoice_series_id', $series->getKey())
            ->where('numbering_period_key', $periodKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function isRetryableNumberingConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        if (in_array($sqlState, ['23000', '23505', '40001', '40P01'], true)) {
            return true;
        }

        return $sqlState === 'HY000'
            && (str_contains($message, 'database is locked')
                || str_contains($message, 'database is busy')
                || str_contains($message, 'deadlock'));
    }
}
