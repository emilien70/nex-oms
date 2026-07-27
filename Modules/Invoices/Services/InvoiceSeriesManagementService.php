<?php

namespace Modules\Invoices\Services;

use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceSeriesManagementService
{
    private const COMMON_EDITABLE_FIELDS = [
        'name',
        'number_format',
        'reset_period',
        'fiscal_year_start_month',
        'default_currency',
    ];

    private const CUSTOM_EDITABLE_FIELDS = [
        'document_type',
        'name',
        'number_format',
        'reset_period',
        'fiscal_year_start_month',
        'default_currency',
        'is_active',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InvoiceSeries
    {
        return DB::transaction(function () use ($data): InvoiceSeries {
            $series = new InvoiceSeries;
            $series->fill($this->normalizeData(Arr::only($data, self::CUSTOM_EDITABLE_FIELDS)));
            $series->is_system = false;
            $series->system_key = null;
            $series->save();

            return $series->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InvoiceSeries $series, array $data): InvoiceSeries
    {
        return DB::transaction(function () use ($series, $data): InvoiceSeries {
            $managedSeries = $this->lockSeries($series);
            $editableFields = $managedSeries->is_system
                ? self::COMMON_EDITABLE_FIELDS
                : self::CUSTOM_EDITABLE_FIELDS;

            $managedSeries->fill($this->normalizeData(Arr::only($data, $editableFields)));

            if ($managedSeries->is_system) {
                $managedSeries->is_active = true;
            } else {
                $managedSeries->is_system = false;
                $managedSeries->system_key = null;
            }

            $managedSeries->save();

            return $managedSeries->refresh();
        });
    }

    public function setActive(InvoiceSeries $series, bool $active): void
    {
        DB::transaction(function () use ($series, $active): void {
            $managedSeries = $this->lockSeries($series);

            if ($managedSeries->is_system && ! $active) {
                throw new DomainException('Seria systemowa jest zawsze aktywna i nie może zostać ukryta.');
            }

            $managedSeries->is_active = $active;
            $managedSeries->save();
        });
    }

    public function delete(InvoiceSeries $series): void
    {
        DB::transaction(function () use ($series): void {
            $managedSeries = $this->lockSeries($series);

            $this->ensureSeriesCanBeDeleted($managedSeries);

            $managedSeries->delete();
        });
    }

    private function lockSeries(InvoiceSeries $series): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->lockForUpdate()
            ->findOrFail($series->getKey());
    }

    private function ensureSeriesCanBeDeleted(InvoiceSeries $series): void
    {
        if ($series->is_system) {
            throw new DomainException('Predefiniowanej serii systemowej nie można usunąć.');
        }

        if ($series->is_active) {
            throw new DomainException('Nie można usunąć aktywnej serii numeracji. Najpierw ją ukryj.');
        }

        if ($series->seriesUsingAsDefaultCorrection()->exists()) {
            throw new DomainException('Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt.');
        }

        // Kontrole dokumentów, liczników i automatyzacji zostaną dopisane po utworzeniu ich tabel.
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeData(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('number_format', $data)) {
            $data['number_format'] = trim((string) $data['number_format']);
        }

        if (array_key_exists('default_currency', $data)) {
            $data['default_currency'] = strtoupper(trim((string) $data['default_currency']));
        }

        return $data;
    }
}
