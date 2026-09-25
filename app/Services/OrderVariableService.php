<?php

namespace App\Services;

use App\Models\Order;
use App\Support\AddressLineFormatter;
use App\Support\CountryCatalog;
use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class OrderVariableService
{
    private const ITEM_VARIABLES = [
        'lista_przedmiotow',
        'liczba_przedmiotow',
    ];

    private const SHIPMENT_VARIABLES = [
        'numery_przesylek',
        'linki_sledzenia',
        'statusy_przesylek',
    ];

    private const DOCUMENT_VARIABLES = [
        'numer_faktury',
        'numer_proformy',
        'numery_korekt',
    ];

    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /**
     * @return array<string, array{token: string, label: string, description: string, example: string, group: string}>
     */
    public function definitions(): array
    {
        $definitions = [
            'id_zamowienia' => $this->definition(
                'ID zam&oacute;wienia',
                'Wewn&#281;trzny numer zam&oacute;wienia w NEX-OMS.',
                '23',
                'Zam&oacute;wienie',
            ),
            'numer_w_sklepie' => $this->definition(
                'Numer w sklepie / transakcji',
                'Warto&#347;&#263; pola external_id dla PrestaShop lub Allegro.',
                'PRESTA-1042',
                'Zam&oacute;wienie',
            ),
            'data_zamowienia' => $this->definition(
                'Data zam&oacute;wienia',
                'Data utworzenia zam&oacute;wienia w formacie DD.MM.RRRR GG:MM.',
                '16.07.2026 15:30',
                'Zam&oacute;wienie',
            ),
            'data_i_czas_zamowienia' => $this->definition(
                'Data i czas zam&oacute;wienia',
                'Data utworzenia zam&oacute;wienia z godzin&#261; w formacie RRRR-MM-DD GG:MM.',
                '2026-07-16 15:30',
                'Zam&oacute;wienie',
            ),
            'uwagi_sprzedawcy' => $this->definition(
                'Uwagi sprzedawcy',
                'Uwagi z sekcji Informacje o zam&oacute;wieniu.',
                'SN001 SN002',
                'Zam&oacute;wienie',
            ),
            'status_zamowienia' => $this->definition(
                'Status zam&oacute;wienia',
                'Aktualna nazwa statusu zam&oacute;wienia.',
                'Oczekuj&#261;ce',
                'Zam&oacute;wienie',
            ),
            'zrodlo_zamowienia' => $this->definition(
                '&#377;r&oacute;d&#322;o zam&oacute;wienia',
                '&#377;r&oacute;d&#322;o zam&oacute;wienia: R&#281;czne, Allegro albo PrestaShop.',
                'Allegro',
                'Zam&oacute;wienie',
            ),
            'login_kupujacego' => $this->definition(
                'Login kupuj&#261;cego',
                'Login zapisany w sekcji Informacje o zam&oacute;wieniu.',
                'kupujacy123',
                'Kupuj&#261;cy',
            ),
            'email_kupujacego' => $this->definition(
                'E-mail kupuj&#261;cego',
                'Adres e-mail zapisany przy zam&oacute;wieniu.',
                'klient@example.com',
                'Kupuj&#261;cy',
            ),
            'telefon_kupujacego' => $this->definition(
                'Telefon kupuj&#261;cego',
                'Numer telefonu zapisany przy zam&oacute;wieniu.',
                '+48 501 294 368',
                'Kupuj&#261;cy',
            ),
            'imie_i_nazwisko_nabywcy' => $this->definition(
                'Imi&#281; i nazwisko nabywcy',
                'Imi&#281; i nazwisko z danych do faktury.',
                'Jan Kowalski',
                'Nabywca',
            ),
            'firma_nabywcy' => $this->definition(
                'Firma nabywcy',
                'Nazwa firmy z danych do faktury.',
                'Kowalski Handel',
                'Nabywca',
            ),
            'adres_nabywcy' => $this->definition(
                'Adres nabywcy',
                'Ulica, numer budynku i numer lokalu z danych do faktury.',
                'Testowa 12/6',
                'Nabywca',
            ),
            'kod_i_miasto_nabywcy' => $this->definition(
                'Kod i miasto nabywcy',
                'Kod pocztowy oraz miasto z danych do faktury.',
                '00-001 Warszawa',
                'Nabywca',
            ),
            'wojewodztwo_nabywcy' => $this->definition(
                'Wojew&oacute;dztwo nabywcy',
                'Wojew&oacute;dztwo zapisane w danych do faktury.',
                'mazowieckie',
                'Nabywca',
            ),
            'kraj_nabywcy' => $this->definition(
                'Kraj nabywcy',
                'Polska nazwa kraju z danych do faktury.',
                'Polska',
                'Nabywca',
            ),
            'email_nabywcy' => $this->definition(
                'E-mail nabywcy',
                'Adres e-mail zapisany w danych do faktury.',
                'faktury@example.com',
                'Nabywca',
            ),
            'telefon_nabywcy' => $this->definition(
                'Telefon nabywcy',
                'Numer telefonu zapisany w danych do faktury.',
                '+48 501 294 368',
                'Nabywca',
            ),
            'imie_i_nazwisko' => $this->definition(
                'Imi&#281; i nazwisko',
                'Imi&#281; i nazwisko z adresu dostawy.',
                'Jan Kowalski',
                'Dostawa',
            ),
            'firma' => $this->definition(
                'Firma',
                'Nazwa firmy z adresu dostawy.',
                'Kowalski Handel',
                'Dostawa',
            ),
            'adres_dostawy' => $this->definition(
                'Adres dostawy',
                'Ulica, numer budynku i numer lokalu.',
                'Testowa 12/6',
                'Dostawa',
            ),
            'kod_i_miasto_dostawy' => $this->definition(
                'Kod i miasto dostawy',
                'Kod pocztowy oraz miasto adresu dostawy.',
                '00-001 Warszawa',
                'Dostawa',
            ),
            'wojewodztwo_dostawy' => $this->definition(
                'Wojew&oacute;dztwo dostawy',
                'Wojew&oacute;dztwo zapisane w adresie dostawy.',
                'mazowieckie',
                'Dostawa',
            ),
            'kraj_dostawy' => $this->definition(
                'Kraj dostawy',
                'Polska nazwa kraju z adresu dostawy.',
                'Polska',
                'Dostawa',
            ),
            'email_dostawy' => $this->definition(
                'E-mail dostawy',
                'Adres e-mail zapisany w adresie dostawy.',
                'odbiorca@example.com',
                'Dostawa',
            ),
            'telefon_dostawy' => $this->definition(
                'Telefon dostawy',
                'Numer telefonu zapisany w adresie dostawy.',
                '+48 501 294 368',
                'Dostawa',
            ),
            'sposob_wysylki' => $this->definition(
                'Spos&oacute;b wysy&#322;ki',
                'Spos&oacute;b wysy&#322;ki zapisany przy zam&oacute;wieniu.',
                'InPost Paczkomaty',
                'Dostawa',
            ),
            'punkt_odbioru' => $this->definition(
                'Punkt odbioru',
                'Nazwa i adres punktu odbioru zapisane przy zam&oacute;wieniu.',
                'Paczkomat WAW01A, Testowa 1, 00-001 Warszawa',
                'Dostawa',
            ),
            'identyfikator_punktu_odbioru' => $this->definition(
                'Identyfikator punktu odbioru',
                'Kod punktu odbioru zapisany przy zam&oacute;wieniu.',
                'WAW01A',
                'Dostawa',
            ),
            'pobranie' => $this->definition(
                'Pobranie',
                'Informacja Tak lub Nie, czy zam&oacute;wienie jest za pobraniem.',
                'Nie',
                'Dostawa',
            ),
            'lista_przedmiotow' => $this->definition(
                'Lista przedmiot&oacute;w',
                'Ka&#380;da pozycja zam&oacute;wienia w osobnym wierszu: ilo&#347;&#263; x nazwa.',
                '2 x Produkt testowy',
                'Przedmioty',
            ),
            'liczba_przedmiotow' => $this->definition(
                'Liczba przedmiot&oacute;w',
                'Suma ilo&#347;ci wszystkich pozycji zam&oacute;wienia.',
                '3',
                'Przedmioty',
            ),
            'nip' => $this->definition(
                'NIP',
                'NIP z danych do faktury.',
                '1234567890',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'kwota_zamowienia' => $this->definition(
                'Kwota zam&oacute;wienia',
                '&#321;&#261;czna warto&#347;&#263; brutto zam&oacute;wienia z dwoma miejscami po przecinku.',
                '158.50',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'status_platnosci' => $this->definition(
                'Status p&#322;atno&#347;ci',
                'Aktualny status p&#322;atno&#347;ci zam&oacute;wienia.',
                'Op&#322;acone',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'sposob_platnosci' => $this->definition(
                'Spos&oacute;b p&#322;atno&#347;ci',
                'Spos&oacute;b p&#322;atno&#347;ci zapisany przy zam&oacute;wieniu.',
                'Przelew',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'kwota_zaplacona' => $this->definition(
                'Kwota zap&#322;acona',
                'Kwota zap&#322;acona z dwoma miejscami po przecinku.',
                '158.50',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'kwota_do_zaplaty' => $this->definition(
                'Kwota do zap&#322;aty',
                'Pozosta&#322;a kwota do zap&#322;aty z dwoma miejscami po przecinku.',
                '0.00',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'koszt_dostawy' => $this->definition(
                'Koszt dostawy',
                'Koszt dostawy brutto z dwoma miejscami po przecinku.',
                '12.99',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'data_platnosci' => $this->definition(
                'Data p&#322;atno&#347;ci',
                'Data zaksi&#281;gowania p&#322;atno&#347;ci w formacie DD.MM.RRRR GG:MM.',
                '16.07.2026 15:45',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'waluta' => $this->definition(
                'Waluta',
                'Kod waluty zam&oacute;wienia.',
                'PLN',
                'P&#322;atno&#347;&#263; i faktura',
            ),
            'numery_przesylek' => $this->definition(
                'Numery przesy&#322;ek',
                'Numery wszystkich utworzonych przesy&#322;ek, ka&#380;dy w osobnym wierszu.',
                '123456789012345678901234',
                'Przesy&#322;ki',
            ),
            'linki_sledzenia' => $this->definition(
                'Linki &#347;ledzenia',
                'Dost&#281;pne linki &#347;ledzenia przesy&#322;ek, ka&#380;dy w osobnym wierszu.',
                'https://inpost.pl/sledzenie-przesylek?number=123456789012345678901234',
                'Przesy&#322;ki',
            ),
            'statusy_przesylek' => $this->definition(
                'Statusy przesy&#322;ek',
                'Aktualne statusy przesy&#322;ek, ka&#380;dy w osobnym wierszu.',
                'W dor&#281;czeniu',
                'Przesy&#322;ki',
            ),
            'numer_faktury' => $this->definition(
                'Numer Faktury VAT',
                'Numer Faktury VAT wystawionej do zam&oacute;wienia.',
                'BL 12/2026',
                'Dokumenty',
            ),
            'numer_proformy' => $this->definition(
                'Numer Pro formy',
                'Numer Pro formy wystawionej do zam&oacute;wienia.',
                'BLPF 12/2026',
                'Dokumenty',
            ),
            'numery_korekt' => $this->definition(
                'Numery Korekt',
                'Numery wszystkich Korekt zam&oacute;wienia, ka&#380;dy w osobnym wierszu.',
                'BLK 2/2026',
                'Dokumenty',
            ),
        ];

        return collect($definitions)
            ->map(fn (array $definition, string $name): array => [
                ...$definition,
                'token' => '['.$name.']',
            ])
            ->all();
    }

    public function render(string $template, Order $order): string
    {
        return $this->replace($template, $order, false);
    }

    public function renderForUrl(string $template, Order $order): string
    {
        return $this->replace($template, $order, true);
    }

    /**
     * @return list<string>
     */
    public function unknownVariables(string $template): array
    {
        preg_match_all('/\[([a-z_][a-z0-9_]*)\]/i', $template, $matches);
        $known = array_keys($this->definitions());

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $name): bool => in_array($name, $known, true))
            ->map(fn (string $name): string => '['.$name.']')
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function values(Order $order, string $template): array
    {
        $createdAt = $order->created_at?->copy()->timezone((string) config('app.timezone'));
        $paidAt = $order->paid_at?->copy()->timezone((string) config('app.timezone'));
        $total = $this->money($order->total_gross);
        $paid = $this->money($order->paid_amount);

        $values = [
            'id_zamowienia' => (string) $order->id,
            'numer_w_sklepie' => (string) ($order->external_id ?? ''),
            'data_zamowienia' => $createdAt?->format('d.m.Y H:i') ?? '',
            'data_i_czas_zamowienia' => $createdAt?->format('Y-m-d H:i') ?? '',
            'uwagi_sprzedawcy' => (string) ($order->notes ?? ''),
            'status_zamowienia' => $order->statusLabel(),
            'zrodlo_zamowienia' => $this->sourceLabel($order->source),
            'login_kupujacego' => (string) ($order->customer_login ?? ''),
            'email_kupujacego' => (string) ($order->customer_email ?? ''),
            'telefon_kupujacego' => (string) ($order->customer_phone ?? ''),
            'imie_i_nazwisko_nabywcy' => (string) ($order->billing_name ?? ''),
            'firma_nabywcy' => (string) ($order->billing_company_name ?? ''),
            'adres_nabywcy' => (string) (AddressLineFormatter::formatAddressLine(
                $order->billing_street,
                $order->billing_building_number,
                $order->billing_apartment_number,
            ) ?? ''),
            'kod_i_miasto_nabywcy' => (string) (AddressLineFormatter::formatPostalCity(
                $order->billing_postal_code,
                $order->billing_city,
            ) ?? ''),
            'wojewodztwo_nabywcy' => (string) ($order->billing_province ?? ''),
            'kraj_nabywcy' => $this->countryName($order->billing_country_code),
            'email_nabywcy' => (string) ($order->billing_email ?? ''),
            'telefon_nabywcy' => (string) ($order->billing_phone ?? ''),
            'imie_i_nazwisko' => (string) ($order->shipping_name ?? ''),
            'firma' => (string) ($order->shipping_company_name ?? ''),
            'adres_dostawy' => (string) (AddressLineFormatter::formatAddressLine(
                $order->shipping_street,
                $order->shipping_building_number,
                $order->shipping_apartment_number,
            ) ?? ''),
            'kod_i_miasto_dostawy' => (string) (AddressLineFormatter::formatPostalCity(
                $order->shipping_postal_code,
                $order->shipping_city,
            ) ?? ''),
            'wojewodztwo_dostawy' => (string) ($order->shipping_province ?? ''),
            'kraj_dostawy' => $this->countryName($order->shipping_country_code),
            'email_dostawy' => (string) ($order->shipping_email ?? ''),
            'telefon_dostawy' => (string) ($order->shipping_phone ?? ''),
            'sposob_wysylki' => (string) ($order->shipping_method ?? ''),
            'punkt_odbioru' => $this->pickupPoint($order),
            'identyfikator_punktu_odbioru' => (string) ($order->pickup_point_id ?? ''),
            'pobranie' => $order->cash_on_delivery ? 'Tak' : 'Nie',
            'nip' => (string) ($order->billing_tax_id ?? ''),
            'kwota_zamowienia' => $total,
            'status_platnosci' => $this->paymentStatusLabel($order->payment_status),
            'sposob_platnosci' => (string) ($order->payment_method ?? ''),
            'kwota_zaplacona' => $paid,
            'kwota_do_zaplaty' => $this->decimal->max(
                $this->decimal->subtract($total, $paid),
                '0.00',
            ),
            'koszt_dostawy' => $this->money($order->delivery_cost_gross),
            'data_platnosci' => $paidAt?->format('d.m.Y H:i') ?? '',
            'waluta' => (string) ($order->currency ?? ''),
        ];

        if ($this->usesAny($template, self::ITEM_VARIABLES)) {
            $order->loadMissing('items');
            $items = $order->items->sortBy('id')->values();

            $values += [
                'lista_przedmiotow' => $items
                    ->map(fn ($item): string => $item->quantity.' x '.$item->product_name)
                    ->implode("\n"),
                'liczba_przedmiotow' => (string) $items->sum('quantity'),
            ];
        }

        if ($this->usesAny($template, self::SHIPMENT_VARIABLES)) {
            $order->loadMissing('visibleShipments');
            $shipments = $order->visibleShipments->sortBy('id')->values();

            $values += [
                'numery_przesylek' => $shipments->pluck('tracking_number')->filter()->implode("\n"),
                'linki_sledzenia' => $shipments
                    ->map(fn ($shipment): ?string => $shipment->trackingUrl())
                    ->filter()
                    ->implode("\n"),
                'statusy_przesylek' => $shipments
                    ->map(fn ($shipment): string => $shipment->statusLabel())
                    ->implode("\n"),
            ];
        }

        if ($this->usesAny($template, self::DOCUMENT_VARIABLES)) {
            $order->loadMissing('invoices');
            $invoices = $order->invoices
                ->filter(fn (Invoice $invoice): bool => $invoice->status === InvoiceDocumentStatus::Issued
                    && filled($invoice->number))
                ->sortBy('id')
                ->values();

            $values += [
                'numer_faktury' => $this->latestDocumentNumber($invoices, InvoiceDocumentType::Invoice),
                'numer_proformy' => $this->latestDocumentNumber($invoices, InvoiceDocumentType::Proforma),
                'numery_korekt' => $this->documentNumbers($invoices, InvoiceDocumentType::Correction),
            ];
        }

        return $values;
    }

    private function replace(string $template, Order $order, bool $encodeForUrl): string
    {
        $replacements = [];

        foreach ($this->values($order, $template) as $name => $value) {
            $replacements['['.$name.']'] = $encodeForUrl ? rawurlencode($value) : $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @return array{label: string, description: string, example: string, group: string}
     */
    private function definition(string $label, string $description, string $example, string $group): array
    {
        return [
            'label' => $this->decode($label),
            'description' => $this->decode($description),
            'example' => $this->decode($example),
            'group' => $this->decode($group),
        ];
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            'manual' => $this->decode('R&#281;czne'),
            'allegro' => 'Allegro',
            'prestashop' => 'PrestaShop',
            default => (string) ($source ?? ''),
        };
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'unpaid' => $this->decode('Nieop&#322;acone'),
            'partial' => $this->decode('Cz&#281;&#347;ciowo op&#322;acone'),
            'paid' => $this->decode('Op&#322;acone'),
            default => (string) ($status ?? ''),
        };
    }

    private function money(string|int|null $value): string
    {
        return $this->decimal->normalize($value, 2);
    }

    private function countryName(?string $code): string
    {
        return (string) ($this->countries->name($code) ?? $this->countries->normalize($code) ?? '');
    }

    private function pickupPoint(Order $order): string
    {
        return collect([
            $order->pickup_point_name,
            $order->pickup_point_address,
            AddressLineFormatter::formatPostalCity(
                $order->pickup_point_postal_code,
                $order->pickup_point_city,
            ),
        ])->filter(fn ($value): bool => filled($value))->implode(', ');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function latestDocumentNumber(Collection $invoices, InvoiceDocumentType $type): string
    {
        return (string) ($invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->document_type === $type)
            ->last()?->number ?? '');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function documentNumbers(Collection $invoices, InvoiceDocumentType $type): string
    {
        return $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->document_type === $type)
            ->pluck('number')
            ->filter()
            ->implode("\n");
    }

    /**
     * @param  list<string>  $names
     */
    private function usesAny(string $template, array $names): bool
    {
        foreach ($names as $name) {
            if (str_contains($template, '['.$name.']')) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
}
