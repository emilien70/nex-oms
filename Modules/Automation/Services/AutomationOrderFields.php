<?php

namespace Modules\Automation\Services;

use App\Services\OrderVariableService;
use App\Support\CountryCatalog;
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutomationOrderFields
{
    public function __construct(private readonly CountryCatalog $countries) {}

    public function definitions(): array
    {
        $fields = [
            'customer_login' => ['label' => 'Login klienta', 'group' => 'Klient', 'type' => 'text', 'max' => 255],
            'customer_email' => ['label' => 'E-mail klienta', 'group' => 'Klient', 'type' => 'email', 'max' => 255],
            'customer_phone' => ['label' => 'Telefon klienta', 'group' => 'Klient', 'type' => 'tel', 'max' => 50],
            'notes' => ['label' => 'Uwagi sprzedawcy', 'group' => 'Pozostałe', 'type' => 'textarea', 'max' => 10000],
            'billing_tax_id' => ['label' => 'NIP', 'group' => 'Dane do faktury', 'type' => 'text', 'max' => 32],
        ];
        $addressFields = [
            'name' => ['Imię i nazwisko', 'text'],
            'company_name' => ['Firma', 'text'],
            'street' => ['Ulica', 'text'],
            'building_number' => ['Numer budynku', 'text'],
            'apartment_number' => ['Numer lokalu', 'text'],
            'postal_code' => ['Kod pocztowy', 'text'],
            'city' => ['Miasto', 'text'],
            'province' => ['Województwo', 'text'],
            'country_code' => ['Kraj', 'country'],
            'phone' => ['Telefon', 'tel'],
            'email' => ['E-mail', 'email'],
        ];
        foreach (['billing' => 'Dane do faktury', 'shipping' => 'Adres dostawy'] as $prefix => $group) {
            foreach ($addressFields as $field => [$label, $type]) {
                $fields[$prefix.'_'.$field] = [
                    'label' => $label, 'group' => $group, 'type' => $type,
                    'max' => $type === 'country' ? 2 : 255,
                ];
            }
        }

        return $fields;
    }

    public function validateConfiguration(array $configuration): void
    {
        Validator::make($configuration, [
            'field' => ['required', 'string', Rule::in(array_keys($this->definitions()))],
            'value' => ['present', 'nullable', 'string', 'max:10000'],
        ])->validate();

        $value = (string) ($configuration['value'] ?? '');
        $variables = app(OrderVariableService::class);
        if ($variables->unknownVariables($value) !== []) {
            throw ValidationException::withMessages(['value' => 'Wartość zawiera nieznane zmienne OMS.']);
        }
        if (! preg_match('/\[[a-zA-Z0-9_]+\]/', $value)) {
            $this->normalize($configuration['field'], $value);
        }
    }

    public function normalize(string $field, string $value): ?string
    {
        $definition = $this->definitions()[$field] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['field' => 'Wybierz dozwolone pole zamówienia.']);
        }

        $value = trim($value);
        if ($definition['type'] === 'country') {
            $value = $this->countries->normalize($value) ?? '';
        }
        $rules = [$definition['type'] === 'country' ? 'required' : 'nullable', 'string', 'max:'.$definition['max']];
        if ($definition['type'] === 'email') {
            $rules[] = 'email';
        }
        if ($definition['type'] === 'country') {
            $rules[] = Rule::in($this->countries->codes());
        }
        Validator::make(['value' => $value === '' ? null : $value], ['value' => $rules])->validate();

        if ($value === '') {
            return null;
        }

        return $definition['type'] === 'tel' ? PhoneNumberFormatter::normalize($value) : $value;
    }
}
