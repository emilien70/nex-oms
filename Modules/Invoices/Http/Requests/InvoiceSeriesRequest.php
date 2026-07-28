<?php

namespace Modules\Invoices\Http\Requests;

use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Enums\InvoicePrimaryLanguage;
use Modules\Invoices\Enums\InvoicePrintTemplate;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Enums\InvoiceSecondaryLanguage;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Enums\InvoiceUnitPriceMode;
use Modules\Invoices\Enums\InvoiceVatRateSource;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceNumberingConfigurationValidator;

abstract class InvoiceSeriesRequest extends FormRequest
{
    private const OPTIONAL_TEXT_FIELDS = [
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
        'seller_bank_swift',
        'place_of_issue',
        'issuer_name',
        'fixed_payment_method',
        'additional_information_template',
        'secondary_language',
    ];

    private const BOOLEAN_FIELDS = [
        'include_shipping',
        'skip_zero_price_items',
        'show_vat_column',
        'show_order_number',
        'show_buyer_signature',
        'show_original_copy',
        'remove_logo',
    ];

    private const PROFORMA_BOOLEAN_FIELDS = [
        'include_shipping',
        'skip_zero_price_items',
        'show_vat_column',
        'show_order_number',
        'show_payment_identifier',
        'show_buyer_signature',
        'show_original_copy',
        'remove_logo',
    ];

    private const CORRECTION_BOOLEAN_FIELDS = [
        'show_correction_item_sequence',
        'show_return_id_in_header',
        'show_payment_identifier',
        'show_vat_column',
        'show_order_number',
        'show_buyer_signature',
        'show_original_copy',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'document_type' => ['required', Rule::enum(InvoiceDocumentType::class)],
            'name' => ['required', 'string', 'max:120', $this->uniqueNameRule()],
            'number_format' => ['required', 'string', 'max:120', 'regex:/%N+/'],
            'reset_period' => ['required', Rule::enum(InvoiceSeriesResetPeriod::class)],
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['required', 'boolean'],
            'form_mode' => ['sometimes', Rule::in(['create', 'edit'])],
            'editing_series_id' => ['nullable', 'integer'],
        ];

        if ($this->finalDocumentType() === InvoiceDocumentType::Invoice) {
            $rules = array_merge($rules, $this->invoiceRules());
        } elseif ($this->finalDocumentType() === InvoiceDocumentType::Correction) {
            $rules = array_merge($rules, $this->correctionRules());
        } elseif ($this->finalDocumentType() === InvoiceDocumentType::Proforma) {
            $rules = array_merge($rules, $this->proformaRules());
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Wybierz typ dokumentu.',
            'document_type.enum' => 'Wybrany typ dokumentu jest nieprawidłowy.',
            'name.required' => 'Podaj nazwę serii.',
            'name.string' => 'Nazwa serii musi być tekstem.',
            'name.max' => 'Nazwa serii nie może być dłuższa niż 120 znaków.',
            'name.unique' => 'Seria numeracji o tej nazwie już istnieje dla wybranego typu dokumentu.',
            'number_format.required' => 'Podaj format numeracji.',
            'number_format.string' => 'Format numeracji musi być tekstem.',
            'number_format.max' => 'Format numeracji nie może być dłuższy niż 120 znaków.',
            'number_format.regex' => 'Format numeracji musi zawierać token %N.',
            'reset_period.required' => 'Wybierz sposób resetowania numeracji.',
            'reset_period.enum' => 'Wybrany sposób resetowania numeracji jest nieprawidłowy.',
            'fiscal_year_start_month.required' => 'Podaj początek roku fiskalnego.',
            'fiscal_year_start_month.integer' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'fiscal_year_start_month.min' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'fiscal_year_start_month.max' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'default_currency.required' => 'Podaj domyślną walutę.',
            'default_currency.regex' => 'Waluta musi składać się z trzech liter.',
            'is_active.required' => 'Wybierz stan aktywności serii.',
            'is_active.boolean' => 'Stan aktywności serii jest nieprawidłowy.',
            'default_correction_series_id.exists' => 'Wybrana seria korekt jest nieprawidłowa.',
            'default_vat_rate.required' => 'Podaj domyślną stawkę VAT.',
            'default_vat_rate.between' => 'Stawka VAT musi mieścić się w zakresie od 0 do 100.',
            'default_vat_rate.decimal' => 'Stawka VAT może mieć maksymalnie 2 miejsca po przecinku.',
            'default_shipping_vat_rate.required' => 'Podaj stawkę VAT kosztu dostawy.',
            'default_shipping_vat_rate.between' => 'Stawka VAT kosztu dostawy musi mieścić się w zakresie od 0 do 100.',
            'default_shipping_vat_rate.decimal' => 'Stawka VAT kosztu dostawy może mieć maksymalnie 2 miejsca po przecinku.',
            'fixed_payment_method.required' => 'Podaj stały sposób płatności.',
            'payment_due_days.required' => 'Podaj liczbę dni na płatność.',
            'payment_due_days.integer' => 'Liczba dni na płatność musi być liczbą całkowitą.',
            'payment_due_days.between' => 'Liczba dni na płatność musi mieścić się w zakresie od 0 do 365.',
            'seller_country_code.size' => 'Kod kraju musi składać się dokładnie z 2 liter.',
            'seller_country_code.alpha' => 'Kod kraju może zawierać wyłącznie litery.',
            'seller_email.email' => 'Adres e-mail sprzedawcy jest nieprawidłowy.',
            'seller_bank_swift.max' => 'Kod SWIFT/BIC nie może być dłuższy niż 11 znaków.',
            'document_title.required' => 'Podaj nazwę dokumentu.',
            'copies_count.required' => 'Podaj liczbę kopii.',
            'copies_count.between' => 'Liczba kopii musi mieścić się w zakresie od 1 do 10.',
            'secondary_language.different' => 'Język dodatkowy musi być inny niż język główny.',
            'logo.image' => 'Logo musi być prawidłowym plikiem graficznym.',
            'logo.mimes' => 'Logo musi być plikiem PNG, JPG, JPEG albo WEBP.',
            'logo.max' => 'Logo nie może być większe niż 2 MB.',
            'default_correction_reason.max' => 'Domyślny powód korekty nie może być dłuższy niż 1000 znaków.',
            'correction_sale_date_source.required' => 'Wybierz sposób ustalania daty sprzedaży.',
            'correction_sale_date_source.enum' => 'Wybrany sposób ustalania daty sprzedaży jest nieprawidłowy.',
            'correction_issuer_source.required' => 'Wybierz źródło osoby wystawiającej.',
            'correction_issuer_source.enum' => 'Wybrane źródło osoby wystawiającej jest nieprawidłowe.',
            'issuer_name.required' => 'Podaj osobę wystawiającą zapisaną w serii korekt.',
            'correction_payment_method_source.required' => 'Wybierz sposób prezentowania płatności.',
            'correction_payment_method_source.enum' => 'Wybrany sposób prezentowania płatności jest nieprawidłowy.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny([
                'number_format',
                'reset_period',
                'fiscal_year_start_month',
            ])) {
                return;
            }

            try {
                app(InvoiceNumberingConfigurationValidator::class)->validate(
                    (string) $this->input('number_format'),
                    (string) $this->input('reset_period'),
                    (int) $this->input('fiscal_year_start_month'),
                );
            } catch (DomainException $exception) {
                $validator->errors()->add('number_format', $exception->getMessage());
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'number_format' => trim((string) $this->input('number_format', '')),
            'default_currency' => strtoupper(trim((string) $this->input('default_currency', ''))),
        ]);

        if ($this->finalDocumentType() === InvoiceDocumentType::Correction) {
            $this->prepareCorrectionForValidation();

            return;
        }

        $documentType = $this->finalDocumentType();
        if (! in_array($documentType, [InvoiceDocumentType::Invoice, InvoiceDocumentType::Proforma], true)) {
            return;
        }

        $this->prepareCommercialDocumentForValidation($documentType);
    }

    private function prepareCommercialDocumentForValidation(InvoiceDocumentType $documentType): void
    {
        $defaults = $documentType === InvoiceDocumentType::Proforma
            ? $this->proformaInputDefaults()
            : $this->invoiceInputDefaults();

        foreach ($defaults as $field => $value) {
            if (! $this->exists($field)) {
                $this->merge([$field => $value]);
            }
        }

        $normalized = [];
        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if ($this->exists($field)) {
                $value = trim((string) $this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        $countryCode = strtoupper(trim((string) $this->input('seller_country_code', '')));
        $normalized['seller_country_code'] = $countryCode === '' ? 'PL' : $countryCode;
        $normalized['seller_bank_swift'] = $normalized['seller_bank_swift'] === null
            ? null
            : strtoupper($normalized['seller_bank_swift']);
        $normalized['document_title'] = trim((string) $this->input('document_title', ''));

        $booleanFields = $documentType === InvoiceDocumentType::Proforma
            ? self::PROFORMA_BOOLEAN_FIELDS
            : self::BOOLEAN_FIELDS;

        foreach ($booleanFields as $field) {
            $value = $this->input($field);
            if (in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                $normalized[$field] = filter_var($value, FILTER_VALIDATE_BOOL);
            }
        }

        foreach (['default_vat_rate', 'default_shipping_vat_rate', 'payment_due_days', 'default_correction_series_id'] as $field) {
            if ($this->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function invoiceRules(): array
    {
        return $this->commercialDocumentRules(true, false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function proformaRules(): array
    {
        return $this->commercialDocumentRules(false, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function commercialDocumentRules(bool $allowCorrectionSeries, bool $showPaymentIdentifier): array
    {
        $rules = [
            'seller_name' => ['nullable', 'string', 'max:255'],
            'seller_tax_id' => ['nullable', 'string', 'max:32'],
            'seller_regon' => ['nullable', 'string', 'max:32'],
            'seller_bdo' => ['nullable', 'string', 'max:64'],
            'seller_street' => ['nullable', 'string', 'max:255'],
            'seller_building_number' => ['nullable', 'string', 'max:32'],
            'seller_apartment_number' => ['nullable', 'string', 'max:32'],
            'seller_postal_code' => ['nullable', 'string', 'max:20'],
            'seller_city' => ['nullable', 'string', 'max:120'],
            'seller_province' => ['nullable', 'string', 'max:120'],
            'seller_country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'seller_email' => ['nullable', 'email', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:64'],
            'seller_bank_name' => ['nullable', 'string', 'max:255'],
            'seller_bank_account' => ['nullable', 'string', 'max:64'],
            'seller_bank_swift' => ['nullable', 'string', 'max:11'],
            'place_of_issue' => ['nullable', 'string', 'max:120'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'vat_rate_source' => ['required', Rule::enum(InvoiceVatRateSource::class)],
            'default_vat_rate' => [
                Rule::requiredIf(fn (): bool => $this->input('vat_rate_source') === InvoiceVatRateSource::Fixed->value),
                'nullable',
                'numeric',
                'between:0,100',
                'decimal:0,2',
            ],
            'include_shipping' => ['required', 'boolean'],
            'shipping_vat_mode' => ['required', Rule::enum(InvoiceShippingVatMode::class)],
            'default_shipping_vat_rate' => [
                Rule::requiredIf(fn (): bool => $this->boolean('include_shipping')
                    && $this->input('shipping_vat_mode') === InvoiceShippingVatMode::Fixed->value),
                'nullable',
                'numeric',
                'between:0,100',
                'decimal:0,2',
            ],
            'skip_zero_price_items' => ['required', 'boolean'],
            'payment_method_source' => ['required', Rule::enum(InvoicePaymentMethodSource::class)],
            'fixed_payment_method' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_method_source') === InvoicePaymentMethodSource::Fixed->value),
                'nullable',
                'string',
                'max:80',
            ],
            'sale_date_source' => ['required', Rule::enum(InvoiceSaleDateSource::class)],
            'payment_due_mode' => ['required', Rule::enum(InvoicePaymentDueMode::class)],
            'payment_due_days' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_due_mode') === InvoicePaymentDueMode::DaysFromIssue->value),
                'nullable',
                'integer',
                'between:0,365',
            ],
            'unit_price_mode' => ['required', Rule::enum(InvoiceUnitPriceMode::class)],
            'show_vat_column' => ['required', 'boolean'],
            'show_order_number' => ['required', 'boolean'],
            'show_buyer_signature' => ['required', 'boolean'],
            'show_original_copy' => ['required', 'boolean'],
            'print_template' => ['required', Rule::enum(InvoicePrintTemplate::class)],
            'primary_language' => ['required', Rule::enum(InvoicePrimaryLanguage::class)],
            'secondary_language' => [
                'nullable',
                Rule::enum(InvoiceSecondaryLanguage::class),
                'different:primary_language',
            ],
            'document_title' => ['required', 'string', 'max:120'],
            'copies_count' => ['required', 'integer', 'between:1,10'],
            'additional_information_template' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['required', 'boolean'],
        ];

        if ($allowCorrectionSeries) {
            $rules['default_correction_series_id'] = [
                'nullable',
                'integer',
                Rule::exists('invoice_series', 'id')->where(
                    fn (Builder $query): Builder => $query->where(
                        'document_type',
                        InvoiceDocumentType::Correction->value,
                    ),
                ),
            ];
        }

        if ($showPaymentIdentifier) {
            $rules['show_payment_identifier'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function correctionRules(): array
    {
        return [
            'default_correction_reason' => ['nullable', 'string', 'max:1000'],
            'correction_sale_date_source' => ['required', Rule::enum(CorrectionSaleDateSource::class)],
            'correction_issuer_source' => ['required', Rule::enum(CorrectionIssuerSource::class)],
            'issuer_name' => [
                Rule::requiredIf(fn (): bool => $this->input('correction_issuer_source') === CorrectionIssuerSource::Series->value),
                'nullable',
                'string',
                'max:255',
            ],
            'correction_payment_method_source' => ['required', Rule::enum(CorrectionPaymentMethodSource::class)],
            'fixed_payment_method' => [
                Rule::requiredIf(fn (): bool => $this->input('correction_payment_method_source') === CorrectionPaymentMethodSource::Fixed->value),
                'nullable',
                'string',
                'max:80',
            ],
            'show_correction_item_sequence' => ['required', 'boolean'],
            'show_return_id_in_header' => ['required', 'boolean'],
            'show_payment_identifier' => ['required', 'boolean'],
            'document_title' => ['required', 'string', 'max:120'],
            'print_template' => ['required', Rule::enum(InvoicePrintTemplate::class)],
            'primary_language' => ['required', Rule::enum(InvoicePrimaryLanguage::class)],
            'secondary_language' => [
                'nullable',
                Rule::enum(InvoiceSecondaryLanguage::class),
                'different:primary_language',
            ],
            'unit_price_mode' => ['required', Rule::enum(InvoiceUnitPriceMode::class)],
            'show_vat_column' => ['required', 'boolean'],
            'show_order_number' => ['required', 'boolean'],
            'show_buyer_signature' => ['required', 'boolean'],
            'show_original_copy' => ['required', 'boolean'],
            'copies_count' => ['required', 'integer', 'between:1,10'],
            'additional_information_template' => ['nullable', 'string', 'max:65535'],
        ];
    }

    private function prepareCorrectionForValidation(): void
    {
        foreach ($this->correctionInputDefaults() as $field => $value) {
            if (! $this->exists($field)) {
                $this->merge([$field => $value]);
            }
        }

        $normalized = [];
        foreach ([
            'default_correction_reason',
            'issuer_name',
            'fixed_payment_method',
            'additional_information_template',
            'secondary_language',
        ] as $field) {
            if ($this->exists($field)) {
                $value = trim((string) $this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        $normalized['document_title'] = trim((string) $this->input('document_title', ''));

        foreach (self::CORRECTION_BOOLEAN_FIELDS as $field) {
            $value = $this->input($field);
            if (in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                $normalized[$field] = filter_var($value, FILTER_VALIDATE_BOOL);
            }
        }

        $this->merge($normalized);
    }

    private function finalDocumentType(): ?InvoiceDocumentType
    {
        $series = $this->route('series');
        if ($series instanceof InvoiceSeries && $series->is_system) {
            return $series->document_type;
        }

        return InvoiceDocumentType::tryFrom((string) $this->input('document_type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionInputDefaults(): array
    {
        $defaults = [
            'default_correction_reason' => null,
            'correction_sale_date_source' => CorrectionSaleDateSource::SourceInvoice->value,
            'correction_issuer_source' => CorrectionIssuerSource::SourceInvoice->value,
            'issuer_name' => null,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::SourceInvoice->value,
            'fixed_payment_method' => null,
            'show_correction_item_sequence' => false,
            'show_return_id_in_header' => false,
            'show_payment_identifier' => false,
            'document_title' => 'Faktura korygująca',
            'print_template' => InvoicePrintTemplate::Standard->value,
            'primary_language' => InvoicePrimaryLanguage::BuyerCountry->value,
            'secondary_language' => null,
            'unit_price_mode' => InvoiceUnitPriceMode::Gross->value,
            'show_vat_column' => true,
            'show_order_number' => false,
            'show_buyer_signature' => false,
            'show_original_copy' => false,
            'copies_count' => 1,
            'additional_information_template' => null,
        ];

        $series = $this->route('series');
        if (! $series instanceof InvoiceSeries || $series->document_type !== InvoiceDocumentType::Correction) {
            return $defaults;
        }

        foreach (array_keys($defaults) as $field) {
            $value = $series->{$field};
            $defaults[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceInputDefaults(): array
    {
        return $this->commercialDocumentInputDefaults(InvoiceDocumentType::Invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function proformaInputDefaults(): array
    {
        return $this->commercialDocumentInputDefaults(InvoiceDocumentType::Proforma);
    }

    /**
     * @return array<string, mixed>
     */
    private function commercialDocumentInputDefaults(InvoiceDocumentType $documentType): array
    {
        $defaults = [
            'seller_name' => null,
            'seller_tax_id' => null,
            'seller_regon' => null,
            'seller_bdo' => null,
            'seller_street' => null,
            'seller_building_number' => null,
            'seller_apartment_number' => null,
            'seller_postal_code' => null,
            'seller_city' => null,
            'seller_province' => null,
            'seller_country_code' => 'PL',
            'seller_email' => null,
            'seller_phone' => null,
            'seller_bank_name' => null,
            'seller_bank_account' => null,
            'seller_bank_swift' => null,
            'place_of_issue' => null,
            'issuer_name' => null,
            'default_correction_series_id' => null,
            'vat_rate_source' => InvoiceVatRateSource::OrderItem->value,
            'default_vat_rate' => null,
            'include_shipping' => true,
            'shipping_vat_mode' => InvoiceShippingVatMode::HighestItem->value,
            'default_shipping_vat_rate' => null,
            'skip_zero_price_items' => false,
            'payment_method_source' => InvoicePaymentMethodSource::Order->value,
            'fixed_payment_method' => null,
            'sale_date_source' => InvoiceSaleDateSource::PaymentOrIssue->value,
            'payment_due_mode' => InvoicePaymentDueMode::None->value,
            'payment_due_days' => null,
            'unit_price_mode' => InvoiceUnitPriceMode::Gross->value,
            'show_vat_column' => true,
            'show_order_number' => false,
            'show_buyer_signature' => false,
            'show_original_copy' => false,
            'print_template' => InvoicePrintTemplate::Standard->value,
            'primary_language' => InvoicePrimaryLanguage::BuyerCountry->value,
            'secondary_language' => null,
            'document_title' => 'Faktura VAT',
            'copies_count' => 1,
            'additional_information_template' => null,
            'remove_logo' => false,
            'show_payment_identifier' => false,
        ];

        if ($documentType === InvoiceDocumentType::Proforma) {
            $defaults = array_replace($defaults, [
                'default_correction_series_id' => null,
                'include_shipping' => false,
                'payment_method_source' => InvoicePaymentMethodSource::None->value,
                'unit_price_mode' => InvoiceUnitPriceMode::Net->value,
                'document_title' => 'Faktura pro forma',
            ]);
        }

        $series = $this->route('series');
        if (! $series instanceof InvoiceSeries || $series->document_type !== $documentType) {
            return $defaults;
        }

        foreach (array_keys($defaults) as $field) {
            if (in_array($field, ['remove_logo'], true)) {
                continue;
            }

            $value = $series->{$field};
            $defaults[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $defaults;
    }

    abstract protected function uniqueNameRule(): Unique;
}
