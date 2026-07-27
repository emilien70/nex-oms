<?php

namespace Modules\Invoices\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Enums\InvoicePrimaryLanguage;
use Modules\Invoices\Enums\InvoicePrintTemplate;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Enums\InvoiceSecondaryLanguage;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Enums\InvoiceUnitPriceMode;
use Modules\Invoices\Enums\InvoiceVatRateSource;

class InvoiceSeries extends Model
{
    protected $table = 'invoice_series';

    protected $fillable = [
        'document_type',
        'name',
        'number_format',
        'reset_period',
        'fiscal_year_start_month',
        'is_active',
        'default_correction_series_id',
        'default_currency',
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
        'logo_path',
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

    protected $casts = [
        'document_type' => InvoiceDocumentType::class,
        'reset_period' => InvoiceSeriesResetPeriod::class,
        'system_key' => InvoiceSeriesSystemKey::class,
        'fiscal_year_start_month' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'vat_rate_source' => InvoiceVatRateSource::class,
        'default_vat_rate' => 'decimal:2',
        'include_shipping' => 'boolean',
        'shipping_vat_mode' => InvoiceShippingVatMode::class,
        'default_shipping_vat_rate' => 'decimal:2',
        'skip_zero_price_items' => 'boolean',
        'payment_method_source' => InvoicePaymentMethodSource::class,
        'sale_date_source' => InvoiceSaleDateSource::class,
        'payment_due_mode' => InvoicePaymentDueMode::class,
        'payment_due_days' => 'integer',
        'unit_price_mode' => InvoiceUnitPriceMode::class,
        'show_vat_column' => 'boolean',
        'show_order_number' => 'boolean',
        'show_buyer_signature' => 'boolean',
        'show_original_copy' => 'boolean',
        'print_template' => InvoicePrintTemplate::class,
        'primary_language' => InvoicePrimaryLanguage::class,
        'secondary_language' => InvoiceSecondaryLanguage::class,
        'copies_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            $wasSystem = $series->exists && (bool) $series->getRawOriginal('is_system');

            if ($wasSystem && $series->isDirty('is_system') && ! $series->is_system) {
                throw new DomainException('Seria systemowa nie może zostać zmieniona na serię własną.');
            }

            if ($wasSystem && $series->isDirty('system_key')) {
                throw new DomainException('Klucz serii systemowej nie może zostać zmieniony.');
            }

            if ($wasSystem && $series->isDirty('document_type')) {
                throw new DomainException('Typ dokumentu serii systemowej nie może zostać zmieniony.');
            }

            if ($series->is_system) {
                if ($series->system_key === null) {
                    throw new DomainException('Seria systemowa musi posiadać klucz systemowy.');
                }

                if ($series->system_key->value !== $series->document_type->value) {
                    throw new DomainException('Klucz serii systemowej musi odpowiadać typowi dokumentu.');
                }

                $series->is_active = true;
            } elseif ($series->system_key !== null) {
                throw new DomainException('Seria własna nie może posiadać klucza systemowego.');
            }
        });

        static::deleting(function (self $series): void {
            if ($series->is_system) {
                throw new DomainException('Seria systemowa nie może zostać usunięta.');
            }
        });
    }

    public function defaultCorrectionSeries(): BelongsTo
    {
        return $this->belongsTo(self::class, 'default_correction_series_id');
    }

    public function seriesUsingAsDefaultCorrection(): HasMany
    {
        return $this->hasMany(self::class, 'default_correction_series_id');
    }
}
