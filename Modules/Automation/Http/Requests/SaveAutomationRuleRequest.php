<?php

namespace Modules\Automation\Http\Requests;

use App\Models\EmailTemplate;
use App\Models\OrderStatusSetting;
use App\Services\OrderVariableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Integrations\NexPrinter\Services\NexPrinterSelectionService;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Shipments\Models\CourierAccount;

class SaveAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $catalog = app(AutomationCatalog::class);

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger' => ['required', 'string', Rule::in(array_keys($catalog->triggers()))],
            'is_active' => ['required', 'boolean'],
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string', Rule::in(array_keys($catalog->conditionDefinitions()))],
            'conditions.*.operator' => ['required', 'string', Rule::in(array_keys($catalog->operators()))],
            'conditions.*.value' => ['nullable'],
            'actions' => ['required', 'array', 'min:1', 'max:20'],
            'actions.*.type' => ['required', 'string', Rule::in(array_keys($catalog->actions()))],
            'actions.*.configuration' => ['nullable', 'array'],
            'actions.*.stop_on_error' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $catalog = app(AutomationCatalog::class);
            $definitions = $catalog->conditionDefinitions();

            foreach ($this->input('conditions', []) as $index => $condition) {
                $definition = $definitions[$condition['field'] ?? ''] ?? null;

                if (! $definition) {
                    continue;
                }

                if (! in_array($condition['operator'] ?? '', $definition['operators'], true)) {
                    $validator->errors()->add("conditions.$index.operator", 'Operator nie pasuje do wybranego warunku.');
                }

                $value = $condition['value'] ?? null;

                if ($value === null || $value === '') {
                    $validator->errors()->add("conditions.$index.value", 'Podaj wartosc warunku.');
                } elseif ($definition['type'] === 'select' && ! array_key_exists((string) $value, $definition['options'])) {
                    $validator->errors()->add("conditions.$index.value", 'Wybrana wartosc warunku jest nieprawidlowa.');
                } elseif ($definition['type'] === 'number' && ! is_numeric($value)) {
                    $validator->errors()->add("conditions.$index.value", 'Wartosc warunku musi byc liczba.');
                }
            }

            foreach ($this->input('actions', []) as $index => $action) {
                $type = $action['type'] ?? '';
                $configuration = $action['configuration'] ?? [];

                if ($type === AutomationCatalog::ACTION_CHANGE_STATUS
                    && ! array_key_exists((string) ($configuration['status'] ?? ''), OrderStatusSetting::orderedStatuses())) {
                    $validator->errors()->add("actions.$index.configuration.status", 'Wybierz istniejacy status zamowienia.');
                }

                if ($type === AutomationCatalog::ACTION_CREATE_SHIPMENT) {
                    if (str_starts_with((string) $this->input('trigger'), 'shipment.')) {
                        $validator->errors()->add("actions.$index.type", 'Akcja tworzenia przesylki nie moze reagowac na zdarzenie przesylki.');
                    }

                    $this->validateShipmentAction($validator, $catalog, $index, $configuration);
                }

                if ($type === AutomationCatalog::ACTION_DELAY) {
                    $minutes = $configuration['minutes'] ?? null;

                    if (filter_var($minutes, FILTER_VALIDATE_INT) === false || (int) $minutes < 1 || (int) $minutes > 86400) {
                        $validator->errors()->add("actions.$index.configuration.minutes", 'Opoznienie musi wynosic od 1 do 86400 minut.');
                    }
                }

                if ($type === AutomationCatalog::ACTION_CALL_URL) {
                    $url = trim((string) ($configuration['url'] ?? ''));
                    $parts = parse_url($url);
                    $scheme = strtolower((string) ($parts['scheme'] ?? ''));

                    if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
                        || ! in_array($scheme, ['http', 'https'], true)) {
                        $validator->errors()->add("actions.$index.configuration.url", 'Podaj prawidlowy adres URL HTTP lub HTTPS.');
                    }

                    $unknownVariables = app(OrderVariableService::class)->unknownVariables($url);

                    if ($unknownVariables !== []) {
                        $validator->errors()->add(
                            "actions.$index.configuration.url",
                            'Nieznane zmienne: '.implode(', ', $unknownVariables).'.',
                        );
                    }
                }

                if ($type === AutomationCatalog::ACTION_ISSUE_INVOICE) {
                    $seriesId = $configuration['invoice_series_id'] ?? null;
                    $validSeries = filter_var(
                        $seriesId,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );

                    if ($validSeries === false || ! InvoiceSeries::query()
                        ->whereKey($validSeries)
                        ->where('document_type', InvoiceDocumentType::Invoice)
                        ->where('is_active', true)
                        ->exists()) {
                        $validator->errors()->add(
                            "actions.$index.configuration.invoice_series_id",
                            'Wybierz aktywną serię numeracji Faktur VAT.',
                        );
                    }
                }

                if ($type === AutomationCatalog::ACTION_SEND_EMAIL) {
                    $templateId = filter_var(
                        $configuration['email_template_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );
                    $template = $templateId === false
                        ? null
                        : EmailTemplate::query()->with('emailAccount')->find($templateId);

                    if (! $template instanceof EmailTemplate
                        || $template->emailAccount === null
                        || ! $template->emailAccount->hasConfiguredPassword()) {
                        $validator->errors()->add(
                            "actions.$index.configuration.email_template_id",
                            'Wybierz szablon e-mail z poprawnie skonfigurowanym kontem SMTP.',
                        );
                    }

                    $recipientCustomer = (bool) ($configuration['recipient_customer'] ?? true);
                    $recipientEmails = $configuration['recipient_emails'] ?? [];

                    if (! is_array($recipientEmails)) {
                        $validator->errors()->add(
                            "actions.$index.configuration.recipient_emails",
                            'Lista dodatkowych odbiorców jest nieprawidłowa.',
                        );
                        $recipientEmails = [];
                    }

                    if (count($recipientEmails) > 10) {
                        $validator->errors()->add(
                            "actions.$index.configuration.recipient_emails",
                            'Możesz podać maksymalnie 10 dodatkowych adresów e-mail.',
                        );
                    }

                    foreach ($recipientEmails as $recipientIndex => $email) {
                        if (! is_string($email)
                            || mb_strlen($email) > 254
                            || preg_match('/[\r\n]/', $email)
                            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                            $validator->errors()->add(
                                "actions.$index.configuration.recipient_emails.$recipientIndex",
                                'Podaj prawidłowy adres e-mail odbiorcy.',
                            );
                        }
                    }

                    if (! $recipientCustomer && $recipientEmails === []) {
                        $validator->errors()->add(
                            "actions.$index.configuration.recipient_emails",
                            'Wybierz klienta lub podaj co najmniej jeden adres e-mail.',
                        );
                    }
                }

                if ($type === AutomationCatalog::ACTION_DECREASE_STOCK
                    && $this->input('trigger') !== 'invoice.issued') {
                    $validator->errors()->add(
                        "actions.$index.type",
                        'Zmniejszenie stanu magazynowego może reagować wyłącznie na wystawienie Faktury VAT.',
                    );
                }

                if ($type === AutomationCatalog::ACTION_PRINT) {
                    if (! array_key_exists(
                        (string) ($configuration['document_type'] ?? ''),
                        $catalog->printDocumentTypes(),
                    )) {
                        $validator->errors()->add(
                            "actions.$index.configuration.document_type",
                            'Wybierz obsługiwany dokument do wydruku.',
                        );
                    }

                    $printerId = filter_var(
                        $configuration['printer_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );
                    $printerAvailable = $printerId !== false
                        && app(NexPrinterSelectionService::class)
                            ->availableForPdf()
                            ->contains(fn ($printer): bool => $printer->getKey() === $printerId);

                    if (! $printerAvailable) {
                        $validator->errors()->add(
                            "actions.$index.configuration.printer_id",
                            'Wybierz aktywną drukarkę obsługującą dokumenty PDF.',
                        );
                    }
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $actions = collect($this->input('actions', []))
            ->map(function (array $action): array {
                $action['stop_on_error'] = filter_var(
                    $action['stop_on_error'] ?? true,
                    FILTER_VALIDATE_BOOLEAN,
                );

                if (($action['type'] ?? '') === AutomationCatalog::ACTION_CREATE_SHIPMENT) {
                    $configuration = $action['configuration'] ?? [];
                    $configuration['provider'] = (string) ($configuration['provider'] ?? CourierAccount::PROVIDER_INPOST_LOCKERS);
                    $configuration['cod_auto'] = filter_var(
                        $configuration['cod_auto'] ?? true,
                        FILTER_VALIDATE_BOOLEAN,
                    );
                    $configuration['additional_services'] = array_values(array_filter(
                        (array) ($configuration['additional_services'] ?? []),
                        fn (mixed $service): bool => is_string($service) && $service !== '',
                    ));
                    $configuration['parcels'] = collect($configuration['parcels'] ?? [])
                        ->filter(fn (mixed $parcel): bool => is_array($parcel))
                        ->map(function (array $parcel): array {
                            $parcel['is_non_standard'] = filter_var(
                                $parcel['is_non_standard'] ?? false,
                                FILTER_VALIDATE_BOOLEAN,
                            );

                            return $parcel;
                        })
                        ->values()
                        ->all();
                    $action['configuration'] = $configuration;
                }

                if (($action['type'] ?? '') === AutomationCatalog::ACTION_ISSUE_INVOICE) {
                    $configuration = $action['configuration'] ?? [];
                    $seriesId = filter_var(
                        $configuration['invoice_series_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );

                    if ($seriesId !== false) {
                        $configuration['invoice_series_id'] = $seriesId;
                    }

                    $action['configuration'] = $configuration;
                }

                if (($action['type'] ?? '') === AutomationCatalog::ACTION_SEND_EMAIL) {
                    $configuration = $action['configuration'] ?? [];
                    $templateId = filter_var(
                        $configuration['email_template_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );

                    if ($templateId !== false) {
                        $configuration['email_template_id'] = $templateId;
                    }

                    $configuration['recipient_customer'] = array_key_exists('recipient_customer', $configuration)
                        ? filter_var($configuration['recipient_customer'], FILTER_VALIDATE_BOOLEAN)
                        : true;
                    $configuration['recipient_emails'] = collect($configuration['recipient_emails'] ?? [])
                        ->filter(fn (mixed $email): bool => is_string($email))
                        ->map(fn (string $email): string => mb_strtolower(trim($email)))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    $action['configuration'] = $configuration;
                }

                if (($action['type'] ?? '') === AutomationCatalog::ACTION_DECREASE_STOCK) {
                    $action['configuration'] = [];
                }

                if (($action['type'] ?? '') === AutomationCatalog::ACTION_PRINT) {
                    $configuration = $action['configuration'] ?? [];
                    $printerId = filter_var(
                        $configuration['printer_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]],
                    );
                    $configuration['document_type'] = (string) (
                        $configuration['document_type'] ?? AutomationCatalog::PRINT_DOCUMENT_INVOICE
                    );

                    if ($printerId !== false) {
                        $configuration['printer_id'] = $printerId;
                    }

                    $action['configuration'] = $configuration;
                }

                return $action;
            })
            ->values()
            ->all();

        $this->merge([
            'is_active' => filter_var($this->input('is_active', false), FILTER_VALIDATE_BOOLEAN),
            'actions' => $actions,
        ]);
    }

    private function validateShipmentAction(
        Validator $validator,
        AutomationCatalog $catalog,
        int $index,
        array $configuration,
    ): void {
        $provider = (string) ($configuration['provider'] ?? CourierAccount::PROVIDER_INPOST_LOCKERS);
        $definition = $catalog->shipmentActionDefinitions()[$provider] ?? null;
        $prefix = "actions.$index.configuration";

        if (! $definition) {
            $validator->errors()->add("$prefix.provider", 'Wybierz aktywna integracje kurierska.');

            return;
        }

        if (! array_key_exists((string) ($configuration['service'] ?? ''), $definition['services'])) {
            $validator->errors()->add("$prefix.service", 'Wybierz usluge obslugiwana przez przewoznika.');
        }

        $insurance = $configuration['insurance_amount'] ?? null;
        if ($insurance !== null && $insurance !== '' && (! is_numeric($insurance) || (float) $insurance < 0)) {
            $validator->errors()->add("$prefix.insurance_amount", 'Wartosc ubezpieczenia nie moze byc ujemna.');
        }

        $selectedAdditionalServices = (array) ($configuration['additional_services'] ?? []);
        if (array_diff($selectedAdditionalServices, array_keys($definition['additional_services'])) !== []) {
            $validator->errors()->add("$prefix.additional_services", 'Wybrano usluge dodatkowa nieobslugiwana przez przewoznika.');
        }

        if ($definition['kind'] === 'locker') {
            if (! array_key_exists((string) ($configuration['parcel_template'] ?? ''), $definition['sizes'])) {
                $validator->errors()->add("$prefix.parcel_template", 'Wybierz gabaryt przesylki.');
            }

            if (mb_strlen((string) ($configuration['target_point_id'] ?? '')) > 64) {
                $validator->errors()->add("$prefix.target_point_id", 'Identyfikator Paczkomatu jest zbyt dlugi.');
            }

            return;
        }

        $parcels = $configuration['parcels'] ?? [];
        if (! is_array($parcels) || $parcels === []) {
            $validator->errors()->add("$prefix.parcels", 'Dodaj co najmniej jedna paczke.');

            return;
        }

        $maxParcels = match ($provider) {
            CourierAccount::PROVIDER_DPD => 100,
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => 10,
            default => 99,
        };
        if (count($parcels) > $maxParcels) {
            $validator->errors()->add("$prefix.parcels", 'Przekroczono maksymalna liczbe paczek.');
        }

        foreach ($parcels as $parcelIndex => $parcel) {
            foreach (['weight', 'length', 'width', 'height'] as $field) {
                $value = $parcel[$field] ?? null;
                $max = $field === 'weight' ? $definition['max_weight'] : $definition['max_dimension'];

                if (! is_numeric($value) || (float) $value <= 0 || (float) $value > $max) {
                    $validator->errors()->add(
                        "$prefix.parcels.$parcelIndex.$field",
                        'Podaj prawidlowa wage i wymiary paczki.',
                    );
                }
            }

            if (! $definition['supports_non_standard'] && (bool) ($parcel['is_non_standard'] ?? false)) {
                $validator->errors()->add(
                    "$prefix.parcels.$parcelIndex.is_non_standard",
                    'Wybrany przewoznik nie obsluguje oznaczenia przesylki niestandardowej.',
                );
            }
        }
    }
}
