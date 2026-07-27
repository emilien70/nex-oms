<?php

namespace Modules\Invoices\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceSeries;
use Throwable;

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

    private const INVOICE_EDITABLE_FIELDS = [
        'seller_name',
        'seller_tax_id',
        'seller_regon',
        'seller_bdo',
        'seller_street',
        'seller_building_number',
        'seller_apartment_number',
        'seller_postal_code',
        'seller_city',
        'seller_province',
        'seller_country_code',
        'seller_email',
        'seller_phone',
        'seller_bank_name',
        'seller_bank_account',
        'seller_bank_swift',
        'place_of_issue',
        'issuer_name',
        'default_correction_series_id',
        'additional_information_template',
        'vat_rate_source',
        'default_vat_rate',
        'include_shipping',
        'shipping_vat_mode',
        'default_shipping_vat_rate',
        'skip_zero_price_items',
        'payment_method_source',
        'fixed_payment_method',
        'sale_date_source',
        'payment_due_mode',
        'payment_due_days',
        'unit_price_mode',
        'show_vat_column',
        'show_order_number',
        'show_buyer_signature',
        'show_original_copy',
        'print_template',
        'primary_language',
        'secondary_language',
        'document_title',
        'copies_count',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InvoiceSeries
    {
        $newLogoPath = null;

        try {
            return DB::transaction(function () use ($data, &$newLogoPath): InvoiceSeries {
                $editableFields = $this->editableFieldsForData($data, self::CUSTOM_EDITABLE_FIELDS);
                $series = new InvoiceSeries;
                $series->fill($this->normalizeData(Arr::only($data, $editableFields)));
                $series->is_system = false;
                $series->system_key = null;
                $series->save();

                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $newLogoPath = $this->storeLogo($series, $data['logo']);
                    $series->logo_path = $newLogoPath;
                    $series->save();
                }

                return $series->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteOwnedLogoPath($newLogoPath, null);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InvoiceSeries $series, array $data): InvoiceSeries
    {
        $oldLogoPath = $series->logo_path;
        $newLogoPath = null;
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        $uploadedLogo = ($data['logo'] ?? null) instanceof UploadedFile ? $data['logo'] : null;

        if ($uploadedLogo !== null) {
            $newLogoPath = $this->storeLogo($series, $uploadedLogo);
        }

        try {
            $updatedSeries = DB::transaction(function () use ($series, $data, $newLogoPath, $removeLogo): InvoiceSeries {
                $managedSeries = $this->lockSeries($series);
                $baseFields = $managedSeries->is_system
                    ? self::COMMON_EDITABLE_FIELDS
                    : self::CUSTOM_EDITABLE_FIELDS;
                $editableFields = $this->editableFieldsForData($data, $baseFields);

                $managedSeries->fill($this->normalizeData(Arr::only($data, $editableFields)));

                if ($newLogoPath !== null) {
                    $managedSeries->logo_path = $newLogoPath;
                } elseif ($removeLogo) {
                    $managedSeries->logo_path = null;
                }

                if ($managedSeries->is_system) {
                    $managedSeries->is_active = true;
                } else {
                    $managedSeries->is_system = false;
                    $managedSeries->system_key = null;
                }

                $managedSeries->save();

                return $managedSeries->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteOwnedLogoPath($newLogoPath, $series->getKey());

            throw $exception;
        }

        if (($newLogoPath !== null || $removeLogo) && $oldLogoPath !== $newLogoPath) {
            $this->deleteOwnedLogoPath($oldLogoPath, $series->getKey());
        }

        return $updatedSeries;
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
        $logoPath = $series->logo_path;

        DB::transaction(function () use ($series): void {
            $managedSeries = $this->lockSeries($series);

            $this->ensureSeriesCanBeDeleted($managedSeries);

            $managedSeries->delete();
        });

        $this->deleteOwnedLogoPath($logoPath, $series->getKey());
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

        foreach ([
            'seller_name',
            'seller_tax_id',
            'seller_regon',
            'seller_bdo',
            'seller_street',
            'seller_building_number',
            'seller_apartment_number',
            'seller_postal_code',
            'seller_city',
            'seller_province',
            'seller_email',
            'seller_phone',
            'seller_bank_name',
            'seller_bank_account',
            'place_of_issue',
            'issuer_name',
            'fixed_payment_method',
        ] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        foreach (['seller_country_code', 'seller_bank_swift'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = strtoupper(trim($data[$field]));
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $baseFields
     * @return array<int, string>
     */
    private function editableFieldsForData(array $data, array $baseFields): array
    {
        $documentType = $data['document_type'] ?? null;

        if ($documentType instanceof InvoiceDocumentType) {
            $documentType = $documentType->value;
        }

        return $documentType === InvoiceDocumentType::Invoice->value
            ? array_merge($baseFields, self::INVOICE_EDITABLE_FIELDS)
            : $baseFields;
    }

    private function storeLogo(InvoiceSeries $series, UploadedFile $logo): string
    {
        $path = $logo->store("invoice-series/logos/{$series->getKey()}", 'local');

        if (! is_string($path) || $path === '') {
            throw new DomainException('Nie udało się zapisać logo serii numeracji.');
        }

        return $path;
    }

    private function deleteOwnedLogoPath(?string $path, ?int $seriesId): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $prefix = $seriesId === null
            ? 'invoice-series/logos/'
            : "invoice-series/logos/{$seriesId}/";

        if (! str_starts_with($path, $prefix)) {
            return;
        }

        Storage::disk('local')->delete($path);
    }
}
