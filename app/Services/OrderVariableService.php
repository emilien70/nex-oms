<?php

namespace App\Services;

use App\Models\Order;
use App\Support\AddressLineFormatter;

class OrderVariableService
{
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
            'sposob_wysylki' => $this->definition(
                'Spos&oacute;b wysy&#322;ki',
                'Spos&oacute;b wysy&#322;ki zapisany przy zam&oacute;wieniu.',
                'InPost Paczkomaty',
                'Dostawa',
            ),
            'pobranie' => $this->definition(
                'Pobranie',
                'Informacja Tak lub Nie, czy zam&oacute;wienie jest za pobraniem.',
                'Nie',
                'Dostawa',
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
            'waluta' => $this->definition(
                'Waluta',
                'Kod waluty zam&oacute;wienia.',
                'PLN',
                'P&#322;atno&#347;&#263; i faktura',
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
    private function values(Order $order): array
    {
        $createdAt = $order->created_at?->copy()->timezone((string) config('app.timezone'));

        return [
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
            'sposob_wysylki' => (string) ($order->shipping_method ?? ''),
            'pobranie' => $order->cash_on_delivery ? 'Tak' : 'Nie',
            'nip' => (string) ($order->billing_tax_id ?? ''),
            'kwota_zamowienia' => number_format((float) $order->total_gross, 2, '.', ''),
            'waluta' => (string) ($order->currency ?? ''),
        ];
    }

    private function replace(string $template, Order $order, bool $encodeForUrl): string
    {
        $replacements = [];

        foreach ($this->values($order) as $name => $value) {
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

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
}
