<?php

namespace Modules\Automation\Services;

use App\Models\EmailTemplate;
use App\Models\OrderStatusSetting;
use Illuminate\Support\Str;
use Modules\Integrations\DPD\Services\DpdServiceResolver;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Services\NexPrinterSelectionService;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\InPostCourierParcelTemplateService;

class AutomationCatalog
{
    public const ACTION_CHANGE_STATUS = 'change_order_status';

    /** The stored value stays unchanged for compatibility with existing rules. */
    public const ACTION_CREATE_SHIPMENT = 'create_inpost_shipment';

    public const ACTION_CREATE_INPOST_SHIPMENT = self::ACTION_CREATE_SHIPMENT;

    public const ACTION_DELAY = 'delay';

    public const ACTION_CALL_URL = 'call_url';

    public const ACTION_ISSUE_INVOICE = 'issue_invoice';

    public const ACTION_SEND_EMAIL = 'send_email';

    public const ACTION_DECREASE_STOCK = 'decrease_stock';

    public const ACTION_PRINT = 'print_document';

    public const PRINT_DOCUMENT_INVOICE = 'invoice';

    public const PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL = 'latest_shipment_label';

    public const PRINT_DOCUMENT_ALL_SHIPMENT_LABELS = 'all_shipment_labels';

    public function __construct(
        private readonly InPostCourierServiceResolver $inPostCourierServices,
        private readonly DpdServiceResolver $dpdServices,
        private readonly InPostCourierParcelTemplateService $parcelTemplates,
        private readonly NexPrinterSelectionService $nexPrinterSelection,
    ) {}

    public function triggers(): array
    {
        return [
            'order.status_changed' => 'Ustawiono status',
            'payment.registered' => $this->decode('Zarejestrowano p&#322;atno&#347;&#263;'),
            'invoice.issued' => 'Wystawiono Fakturę VAT',
            'shipment.created' => $this->decode('Utworzono przesy&#322;k&#281;'),
            'shipment.status_changed' => $this->decode('Zmieniono status przesy&#322;ki'),
            'shipment.creation_failed' => $this->decode('Wyst&#261;pi&#322; b&#322;&#261;d tworzenia przesy&#322;ki'),
            'ksef.invoice_accepted' => 'Faktura zaakceptowana w KSeF',
        ];
    }

    public function conditionDefinitions(): array
    {
        return [
            'order_status' => [
                'label' => $this->decode('Status zam&oacute;wienia'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => OrderStatusSetting::orderedStatuses(),
            ],
            'source' => [
                'label' => $this->decode('&#377;r&oacute;d&#322;o'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => [
                    'manual' => $this->decode('R&#281;czne'),
                    'allegro' => 'Allegro',
                    'prestashop' => 'PrestaShop',
                ],
            ],
            'cash_on_delivery' => [
                'label' => 'Pobranie',
                'type' => 'select',
                'operators' => ['equals'],
                'options' => ['1' => 'Tak', '0' => 'Nie'],
            ],
            'payment_state' => [
                'label' => $this->decode('P&#322;atno&#347;&#263;'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => [
                    'unpaid' => $this->decode('Nieop&#322;acone'),
                    'partial' => $this->decode('Cz&#281;&#347;ciowo op&#322;acone'),
                    'paid' => $this->decode('Op&#322;acone'),
                ],
            ],
            'payment_source' => [
                'label' => $this->decode('&#377;r&oacute;d&#322;o p&#322;atno&#347;ci'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => [
                    'paynow' => 'PayNow',
                ],
            ],
            'shipping_method' => [
                'label' => $this->decode('Spos&oacute;b wysy&#322;ki'),
                'type' => 'text',
                'operators' => ['equals', 'not_equals', 'contains'],
                'options' => [],
            ],
            'carrier' => [
                'label' => $this->decode('Przewo&#378;nik'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => [
                    CourierAccount::PROVIDER_INPOST_LOCKERS => 'InPost Paczkomaty',
                    CourierAccount::PROVIDER_INPOST_COURIER => 'InPost Kurier',
                    CourierAccount::PROVIDER_DPD => 'DPD',
                    CourierAccount::PROVIDER_ALLEGRO_SHIPPING => 'Wysylam z Allegro',
                ],
            ],
            'shipment_status' => [
                'label' => $this->decode('Status przesy&#322;ki'),
                'type' => 'select',
                'operators' => ['equals', 'not_equals'],
                'options' => Shipment::omsStatuses(),
            ],
            'total_gross' => [
                'label' => $this->decode('&#321;&#261;czna kwota'),
                'type' => 'number',
                'operators' => ['equals', 'greater_or_equal', 'less_or_equal'],
                'options' => [],
            ],
        ];
    }

    public function operators(): array
    {
        return [
            'equals' => $this->decode('R&oacute;wne'),
            'not_equals' => $this->decode('R&oacute;&#380;ne'),
            'contains' => $this->decode('Zawiera fraz&#281;'),
            'greater_or_equal' => $this->decode('Wi&#281;ksze lub r&oacute;wne'),
            'less_or_equal' => $this->decode('Mniejsze lub r&oacute;wne'),
        ];
    }

    public function actions(): array
    {
        return [
            self::ACTION_CHANGE_STATUS => $this->decode('Zmie&#324; status'),
            self::ACTION_CREATE_SHIPMENT => $this->decode('Utw&oacute;rz przesy&#322;k&#281;'),
            self::ACTION_DELAY => 'Poczekaj przez',
            self::ACTION_CALL_URL => $this->decode('Wywo&#322;aj URL'),
            self::ACTION_ISSUE_INVOICE => $this->decode('Wystaw Faktur&#281;'),
            self::ACTION_SEND_EMAIL => $this->decode('Wy&#347;lij e-mail'),
            self::ACTION_DECREASE_STOCK => 'Zmniejsz stan magazynowy',
            self::ACTION_PRINT => 'Drukuj',
        ];
    }

    public function triggerLabel(string $trigger): string
    {
        return $this->triggers()[$trigger] ?? $trigger;
    }

    public function actionLabel(string $action): string
    {
        return $this->actions()[$action] ?? $action;
    }

    public function conditionSummary(array $condition): string
    {
        $definition = $this->conditionDefinitions()[$condition['field'] ?? ''] ?? null;
        $operator = $this->operators()[$condition['operator'] ?? ''] ?? ($condition['operator'] ?? '');
        $value = $condition['value'] ?? '';

        if ($definition && isset($definition['options'][(string) $value])) {
            $value = $definition['options'][(string) $value];
        }

        return trim(($definition['label'] ?? ($condition['field'] ?? '')).' '.$operator.' '.$value);
    }

    public function actionSummary(string $type, array $configuration): string
    {
        return match ($type) {
            self::ACTION_CHANGE_STATUS => $this->actionLabel($type).': '
                .(OrderStatusSetting::labelFor((string) ($configuration['status'] ?? '')) ?? ($configuration['status'] ?? '...')),
            self::ACTION_CREATE_SHIPMENT => $this->shipmentActionSummary($type, $configuration),
            self::ACTION_DELAY => $this->actionLabel($type).' '.((int) ($configuration['minutes'] ?? 0)).' min',
            self::ACTION_CALL_URL => $this->actionLabel($type).': '
                .Str::limit((string) ($configuration['url'] ?? '...'), 80),
            self::ACTION_ISSUE_INVOICE => $this->invoiceActionSummary($type, $configuration),
            self::ACTION_SEND_EMAIL => $this->emailActionSummary($type, $configuration),
            self::ACTION_DECREASE_STOCK => $this->actionLabel($type),
            self::ACTION_PRINT => $this->printActionSummary($type, $configuration),
            default => $type,
        };
    }

    public function emailTemplates(): array
    {
        return EmailTemplate::query()
            ->with('emailAccount')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (EmailTemplate $template): bool => (
                $template->emailAccount !== null
                && $template->emailAccount->hasConfiguredPassword()
            ))
            ->mapWithKeys(function (EmailTemplate $template): array {
                $label = $template->name.' — '.$template->emailAccount->name;

                if ($template->is_hidden) {
                    $label .= ' (ukryty na liście ręcznej)';
                }

                return [$template->getKey() => $label];
            })
            ->all();
    }

    public function invoiceSeries(): array
    {
        return InvoiceSeries::query()
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->where('is_active', true)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    public function nexPrinters(): array
    {
        return $this->nexPrinterSelection
            ->availableForPdf()
            ->mapWithKeys(static fn (NexPrinter $printer): array => [
                $printer->getKey() => $printer->display_name.' ('.$printer->agent->name.')',
            ])
            ->all();
    }

    public function printDocumentTypes(): array
    {
        return [
            self::PRINT_DOCUMENT_INVOICE => 'Faktura VAT',
            self::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL => 'Najnowsza etykieta',
            self::PRINT_DOCUMENT_ALL_SHIPMENT_LABELS => 'Wszystkie etykiety',
        ];
    }

    public function parcelTemplates(): array
    {
        return [
            'small' => 'Gabaryt A',
            'medium' => 'Gabaryt B',
            'large' => 'Gabaryt C',
        ];
    }

    public function inPostServices(): array
    {
        return [
            '' => $this->decode('Automatycznie wed&#322;ug &#378;r&oacute;d&#322;a zam&oacute;wienia'),
            Shipment::SERVICE_INPOST_LOCKER_STANDARD => $this->decode('Paczkomaty 24/7 - Przesy&#322;ka standardowa'),
            Shipment::SERVICE_INPOST_LOCKER_ALLEGRO => 'Allegro Paczkomaty 24/7 InPost',
        ];
    }

    public function shipmentProviderLabels(): array
    {
        return [
            CourierAccount::PROVIDER_INPOST_LOCKERS => 'InPost Paczkomaty',
            CourierAccount::PROVIDER_INPOST_COURIER => 'InPost Kurier',
            CourierAccount::PROVIDER_DPD => 'DPD',
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => 'Wysylam z Allegro',
        ];
    }

    public function shipmentActionDefinitions(): array
    {
        $accounts = CourierAccount::query()
            ->whereIn('provider', array_keys($this->shipmentProviderLabels()))
            ->where('is_active', true)
            ->get()
            ->keyBy('provider');

        return collect($this->shipmentProviderLabels())
            ->mapWithKeys(function (string $fallbackLabel, string $provider) use ($accounts): array {
                /** @var CourierAccount|null $account */
                $account = $accounts->get($provider);

                if (! $account) {
                    return [];
                }

                $definition = match ($provider) {
                    CourierAccount::PROVIDER_INPOST_LOCKERS => $this->lockerDefinition($account),
                    CourierAccount::PROVIDER_INPOST_COURIER => $this->parcelDefinition(
                        $account,
                        $this->inPostCourierServices->serviceLabels(),
                        [
                            Shipment::ADDITIONAL_SERVICE_SMS => 'Powiadomienie SMS',
                            Shipment::ADDITIONAL_SERVICE_EMAIL => 'Powiadomienie e-mail',
                            Shipment::ADDITIONAL_SERVICE_SATURDAY => $this->decode('Dor&#281;czenie w sobot&#281;'),
                            Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => $this->decode('Zwrot dokument&oacute;w'),
                        ],
                        true,
                        50,
                        350,
                    ),
                    CourierAccount::PROVIDER_DPD => $this->parcelDefinition(
                        $account,
                        $this->dpdServices->serviceLabels(),
                        [
                            Shipment::ADDITIONAL_SERVICE_SATURDAY => $this->decode('Dor&#281;czenie w sobot&#281;'),
                            Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => $this->decode('Zwrot dokument&oacute;w'),
                        ],
                        false,
                        700,
                        300,
                    ),
                    CourierAccount::PROVIDER_ALLEGRO_SHIPPING => $this->parcelDefinition(
                        $account,
                        [Shipment::SERVICE_ALLEGRO_DELIVERY => 'Metoda z zamowienia Allegro'],
                        [],
                        false,
                        1000,
                        500,
                    ),
                };

                return [$provider => [
                    'provider' => $provider,
                    'label' => $account->name ?: $fallbackLabel,
                    ...$definition,
                ]];
            })
            ->all();
    }

    private function lockerDefinition(CourierAccount $account): array
    {
        return [
            'kind' => 'locker',
            'services' => $this->inPostServices(),
            'default_service' => '',
            'sizes' => $this->parcelTemplates(),
            'default_size' => (string) $account->setting('default_parcel_template', 'medium'),
            'additional_services' => [
                Shipment::ADDITIONAL_SERVICE_WEEKEND => 'Paczka w Weekend',
                Shipment::ADDITIONAL_SERVICE_RETURN_LABEL => 'Etykieta zwrotna',
            ],
            'default_additional_services' => [],
            'default_insurance_amount' => 0,
        ];
    }

    private function parcelDefinition(
        CourierAccount $account,
        array $services,
        array $additionalServices,
        bool $supportsNonStandard,
        int $maxWeight,
        int $maxDimension,
    ): array {
        return [
            'kind' => 'parcels',
            'services' => $services,
            'default_service' => (string) $account->setting('default_service', array_key_first($services)),
            'templates' => $this->parcelTemplates->all($account),
            'default_parcel' => [
                'weight' => $this->measurement($account->setting('default_weight', 1)),
                'length' => $this->measurement($account->setting('default_length', 25)),
                'width' => $this->measurement($account->setting('default_width', 20)),
                'height' => $this->measurement($account->setting('default_height', 10)),
                'is_non_standard' => false,
            ],
            'additional_services' => $additionalServices,
            'default_additional_services' => $this->defaultAdditionalServices($account, array_keys($additionalServices)),
            'default_insurance_amount' => $this->measurement($account->setting('default_insurance_amount', 0)),
            'supports_non_standard' => $supportsNonStandard,
            'max_weight' => $maxWeight,
            'max_dimension' => $maxDimension,
        ];
    }

    private function defaultAdditionalServices(CourierAccount $account, array $services): array
    {
        $settingByService = [
            Shipment::ADDITIONAL_SERVICE_SMS => 'default_sms',
            Shipment::ADDITIONAL_SERVICE_EMAIL => 'default_email',
            Shipment::ADDITIONAL_SERVICE_SATURDAY => 'default_saturday',
            Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => 'default_return_documents',
        ];

        return collect($services)
            ->filter(fn (string $service): bool => (bool) $account->setting($settingByService[$service] ?? '', false))
            ->values()
            ->all();
    }

    private function shipmentActionSummary(string $type, array $configuration): string
    {
        $provider = (string) ($configuration['provider'] ?? CourierAccount::PROVIDER_INPOST_LOCKERS);
        $providerLabel = $this->shipmentProviderLabels()[$provider] ?? $provider;

        if ($provider === CourierAccount::PROVIDER_INPOST_LOCKERS) {
            $details = $this->parcelTemplates()[$configuration['parcel_template'] ?? 'medium'] ?? 'Gabaryt B';
        } else {
            $count = max(1, count($configuration['parcels'] ?? []));
            $details = $count.' '.($count === 1 ? 'paczka' : 'paczki');
        }

        return $this->actionLabel($type).' ('.$providerLabel.', '.$details.')';
    }

    private function invoiceActionSummary(string $type, array $configuration): string
    {
        $seriesId = filter_var(
            $configuration['invoice_series_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $seriesName = $seriesId === false
            ? null
            : InvoiceSeries::query()
                ->whereKey($seriesId)
                ->where('document_type', InvoiceDocumentType::Invoice)
                ->value('name');

        return $this->actionLabel($type).': '.($seriesName ?? 'brak serii');
    }

    private function emailActionSummary(string $type, array $configuration): string
    {
        $templateId = filter_var(
            $configuration['email_template_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $templateName = $templateId === false
            ? null
            : EmailTemplate::query()->whereKey($templateId)->value('name');

        $recipientCustomer = array_key_exists('recipient_customer', $configuration)
            ? filter_var($configuration['recipient_customer'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $additionalCount = count(array_unique(array_filter(
            (array) ($configuration['recipient_emails'] ?? []),
            'is_string',
        )));
        $recipients = match (true) {
            $recipientCustomer && $additionalCount > 0 => 'klient + '.$this->emailAddressCount($additionalCount),
            $recipientCustomer => 'klient',
            $additionalCount > 0 => $this->emailAddressCount($additionalCount),
            default => 'brak odbiorcy',
        };

        return $this->actionLabel($type).': '.($templateName ?? 'szablon niedostępny').' → '.$recipients;
    }

    private function printActionSummary(string $type, array $configuration): string
    {
        $printerId = filter_var(
            $configuration['printer_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $printer = $printerId === false
            ? null
            : NexPrinter::query()->with('agent')->find($printerId);
        $printerName = $printer === null
            ? 'drukarka niedostępna'
            : $printer->display_name.($printer->agent ? ' ('.$printer->agent->name.')' : '');
        $documentName = $this->printDocumentTypes()[$configuration['document_type'] ?? '']
            ?? 'dokument niedostępny';

        return $this->actionLabel($type).': '.$documentName.' → '.$printerName;
    }

    private function emailAddressCount(int $count): string
    {
        if ($count === 1) {
            return '1 adres';
        }

        if ($count >= 2 && $count <= 4) {
            return $count.' adresy';
        }

        return $count.' adresów';
    }

    private function measurement(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
}
