<?php

namespace Modules\Invoices\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceSeriesManagementService
{
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
}
