<?php

namespace Modules\Invoices\Services;

use App\Support\CurrencyCatalog;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;
use Throwable;

class InvoiceSeriesManagementService
{
    private const NUMBERING_IDENTITY_FIELDS = [
        'document_type',
        'number_format',
        'reset_period',
        'fiscal_year_start_month',
    ];

    private const SYSTEM_NUMBERING_IDENTITY_FIELDS = [
        'document_type',
    ];

    private const NUMBERING_IDENTITY_LOCK_MESSAGE = 'Nie można zmienić parametrów numeracji, ponieważ seria została już użyta do numerowania dokumentów.';

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

    private const COMMERCIAL_DOCUMENT_EDITABLE_FIELDS = [
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
        'print_header',
        'copies_count',
    ];

    private const INVOICE_EDITABLE_FIELDS = [
        'default_correction_series_id',
    ];

    private const PROFORMA_EDITABLE_FIELDS = [
        'show_payment_identifier',
    ];

    private const CORRECTION_EDITABLE_FIELDS = [
        'default_correction_reason',
        'correction_sale_date_source',
        'correction_issuer_source',
        'issuer_name',
        'correction_payment_method_source',
        'fixed_payment_method',
        'additional_information_template',
        'show_correction_item_sequence',
        'show_return_id_in_header',
        'show_payment_identifier',
        'document_title',
        'print_header',
        'print_template',
        'primary_language',
        'secondary_language',
        'unit_price_mode',
        'show_vat_column',
        'show_order_number',
        'show_buyer_signature',
        'show_original_copy',
        'copies_count',
    ];

    public function __construct(
        private readonly InvoiceNumberingConfigurationValidator $numberingConfigurationValidator,
        private readonly CurrencyCatalog $currencies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InvoiceSeries
    {
        $newLogoPath = null;

        try {
            return DB::transaction(function () use ($data, &$newLogoPath): InvoiceSeries {
                $editableFields = $this->editableFieldsForData($data, self::CUSTOM_EDITABLE_FIELDS);
                $normalizedData = $this->normalizeData(Arr::only($data, $editableFields));
                $normalizedData['default_currency'] = $this->currencies->require(
                    $normalizedData['default_currency'] ?? null,
                );
                $this->ensureNumberingConfigurationIsValid($normalizedData);
                $series = new InvoiceSeries;
                $series->fill($normalizedData);
                $series->is_system = false;
                $series->system_key = null;
                $series->save();

                $finalDocumentType = $this->finalDocumentType($data);
                if ($finalDocumentType === InvoiceDocumentType::Proforma->value) {
                    $series->default_correction_series_id = null;
                    $series->save();
                }

                if (in_array($finalDocumentType, [
                    InvoiceDocumentType::Invoice->value,
                    InvoiceDocumentType::Proforma->value,
                ], true)
                    && ($data['logo'] ?? null) instanceof UploadedFile) {
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
        $finalDocumentType = $this->finalDocumentType($data, $series);
        $allowLogoChanges = in_array($finalDocumentType, [
            InvoiceDocumentType::Invoice->value,
            InvoiceDocumentType::Proforma->value,
        ], true);
        $removeLogo = $allowLogoChanges && (bool) ($data['remove_logo'] ?? false);
        $uploadedLogo = $allowLogoChanges && ($data['logo'] ?? null) instanceof UploadedFile
            ? $data['logo']
            : null;

        if ($uploadedLogo !== null) {
            $newLogoPath = $this->storeLogo($series, $uploadedLogo);
        }

        try {
            $updatedSeries = DB::transaction(function () use ($series, $data, $newLogoPath, $removeLogo): InvoiceSeries {
                $managedSeries = $this->lockSeries($series);
                $this->ensureNumberingIdentityCanBeChanged($managedSeries, $data);
                $baseFields = $managedSeries->is_system
                    ? self::COMMON_EDITABLE_FIELDS
                    : self::CUSTOM_EDITABLE_FIELDS;
                $editableFields = $this->editableFieldsForData($data, $baseFields, $managedSeries);
                $normalizedData = $this->normalizeData(Arr::only($data, $editableFields));
                $normalizedData['default_currency'] = $this->currencies->require(
                    $normalizedData['default_currency'] ?? $managedSeries->default_currency,
                    $managedSeries->default_currency,
                );
                $this->ensureNumberingConfigurationIsValid($normalizedData, $managedSeries);

                $managedSeries->fill($normalizedData);

                if ($this->finalDocumentType($data, $managedSeries) === InvoiceDocumentType::Proforma->value) {
                    $managedSeries->default_correction_series_id = null;
                }

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

            if ($active) {
                $this->numberingConfigurationValidator->validateSeries($managedSeries);
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

        if ($series->invoices()->exists()) {
            throw new DomainException(
                'Nie można usunąć serii numeracji, ponieważ została użyta w dokumentach. Serię można ukryć i później ponownie aktywować.'
            );
        }

        if ($series->seriesUsingAsDefaultCorrection()->exists()) {
            throw new DomainException('Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt.');
        }

        // Przyszłe kontrole automatyzacji należy dodać w tym miejscu.
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureNumberingIdentityCanBeChanged(InvoiceSeries $series, array $data): void
    {
        if (! $series->numberingHasStarted()) {
            return;
        }

        $lockedFields = $series->is_system
            ? self::SYSTEM_NUMBERING_IDENTITY_FIELDS
            : self::NUMBERING_IDENTITY_FIELDS;

        foreach ($lockedFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $series->{$field};
            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }

            $incoming = $data[$field];
            if ($incoming instanceof \BackedEnum) {
                $incoming = $incoming->value;
            }

            if ((string) $current !== (string) $incoming) {
                throw new DomainException(self::NUMBERING_IDENTITY_LOCK_MESSAGE);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureNumberingConfigurationIsValid(
        array $data,
        ?InvoiceSeries $series = null,
    ): void {
        $this->numberingConfigurationValidator->validate(
            (string) ($data['number_format'] ?? $series?->number_format ?? ''),
            $data['reset_period'] ?? $series?->reset_period ?? InvoiceSeriesResetPeriod::Yearly,
            (int) ($data['fiscal_year_start_month'] ?? $series?->fiscal_year_start_month ?? 1),
        );
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
            $data['default_currency'] = $this->currencies->normalize($data['default_currency']);
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
            'default_correction_reason',
            'additional_information_template',
            'document_title',
            'print_header',
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
    private function editableFieldsForData(
        array $data,
        array $baseFields,
        ?InvoiceSeries $series = null,
    ): array {
        return match ($this->finalDocumentType($data, $series)) {
            InvoiceDocumentType::Invoice->value => array_merge(
                $baseFields,
                self::COMMERCIAL_DOCUMENT_EDITABLE_FIELDS,
                self::INVOICE_EDITABLE_FIELDS,
            ),
            InvoiceDocumentType::Proforma->value => array_merge(
                $baseFields,
                self::COMMERCIAL_DOCUMENT_EDITABLE_FIELDS,
                self::PROFORMA_EDITABLE_FIELDS,
            ),
            InvoiceDocumentType::Correction->value => array_merge($baseFields, self::CORRECTION_EDITABLE_FIELDS),
            default => $baseFields,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function finalDocumentType(array $data, ?InvoiceSeries $series = null): ?string
    {
        if ($series?->is_system) {
            return $series->document_type->value;
        }

        $documentType = $data['document_type'] ?? $series?->document_type;

        return $documentType instanceof InvoiceDocumentType
            ? $documentType->value
            : (is_string($documentType) ? $documentType : null);
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
