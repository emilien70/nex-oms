<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\ValueObjects\SalesRegisterFilters;

final class SalesRegisterHtmlPresenter
{
    private const SECTIONS = [
        'original' => 'Kwoty oryginalne', 'totals' => 'Kwoty', 'vat' => 'Grupy VAT', 'shipping' => 'Wysyłka',
        'pln' => 'Przeliczenie PLN', 'pln_vat' => 'Grupy VAT w PLN', 'shipping_pln' => 'Wysyłka w PLN',
        'country' => 'Podział na kraje', 'buyer' => 'Nabywca', 'document' => 'Dokument',
        'correction' => 'Korekta', 'related_documents' => 'Powiązania', 'ksef' => 'Numer KSeF', 'selection' => 'Wybór dokumentów',
        'payment' => 'Sposób płatności', 'exchange_rate' => 'Kurs waluty',
    ];

    private const WARNINGS = [
        'buyer_snapshot_missing' => 'Brak snapshotu nabywcy.', 'buyer_name_missing' => 'Brak nazwy nabywcy.',
        'buyer_address_incomplete' => 'Niekompletny adres nabywcy.', 'buyer_country_unknown' => 'Nieustalony kraj nabywcy.',
        'buyer_name_legacy_scalar' => 'Nazwa nabywcy pochodzi z osobnego pola historycznego.',
        'buyer_tax_id_legacy_scalar' => 'NIP pochodzi z osobnego pola historycznego.',
        'buyer_name_conflict' => 'Sprzeczne zapisane nazwy nabywcy.', 'buyer_tax_id_conflict' => 'Sprzeczne zapisane identyfikatory podatkowe.',
        'buyer_name_invalid' => 'Nieprawidłowa zapisana nazwa nabywcy.', 'buyer_tax_id_invalid' => 'Nieprawidłowy zapisany identyfikator podatkowy.',
        'currency_invalid' => 'Nieustalona waluta.', 'totals_invalid' => 'Brak lub niespójność kwot dokumentu.',
        'vat_summary_invalid' => 'Brak lub niespójność podsumowania VAT.', 'shipping_incomplete' => 'Niekompletne dane wysyłki.',
        'pln_conversion_unavailable' => 'Brak poprawnego zapisanego przeliczenia PLN.',
        'shipping_pln_unavailable' => 'Brak danych do podsumowania wysyłki w PLN.',
        'correction_difference_missing' => 'Brak dodatkowego snapshotu różnicy Korekty; użyto zapisanych kwot dokumentu.',
        'correction_difference_conflict' => 'Kwoty Korekty są sprzeczne z zapisanym snapshotem różnicy.',
        'correction_tax_difference_missing' => 'Brak snapshotu różnicy VAT Korekty.',
        'correction_tax_difference_conflict' => 'Sprzeczne snapshoty różnicy VAT Korekty.',
        'related_invoice_snapshot_missing' => 'Brak historycznego numeru Faktury źródłowej.',
        'number_missing' => 'Brak numeru dokumentu.', 'issue_date_missing' => 'Brak daty wystawienia.', 'sale_date_missing' => 'Brak daty sprzedaży.',
        'tax_id_filter_unresolved' => 'Dokument pominięty: nie można ustalić obecności identyfikatora podatkowego.',
        'ksef_link_invalid' => 'Niespójne powiązanie produkcyjnego numeru KSeF.',
        'ksef_number_ambiguous' => 'Więcej niż jeden produkcyjny numer KSeF.',
        'ksef_authorization_date_unavailable' => 'Brak prawidłowej daty autoryzacji przy zaakceptowanym numerze KSeF.',
        'ksef_authorization_date_conflict' => 'Sprzeczne daty autoryzacji tego samego numeru KSeF.',
        'payment_method_missing' => 'Brak zapisanej metody płatności dokumentu.',
        'payment_method_invalid' => 'Nieprawidłowa zapisana metoda płatności dokumentu.',
        'exchange_rate_unavailable' => 'Brak poprawnego historycznego kursu waluty dokumentu.',
        'xlsx_amount_as_text' => 'Kwota zapisana jako tekst, aby zachować dokładność poza bezpieczną precyzją liczb Excela.',
        'xml_order_id_conflict' => 'Sprzeczne historyczne identyfikatory zamówienia; pole pozostawiono puste.',
        'xml_order_id_unavailable' => 'Brak jednoznacznego historycznego ID zamówienia OMS.',
        'xml_shop_order_id_unavailable' => 'Brak potwierdzonego historycznego identyfikatora zamówienia sklepu.',
        'xml_order_items_unavailable' => 'Brak pełnego historycznego snapshotu pozycji zamówienia; order_items pozostaje puste.',
        'xml_item_identifiers_unavailable' => 'Co najmniej jedna pozycja nie ma historycznego ID produktu lub SKU; brakujące pola pozostają puste.',
        'xml_seller_unavailable' => 'Brak własnych zapisanych danych sprzedawcy.',
        'xml_recipient_country_unavailable' => 'Brak poprawnego historycznego kraju odbiorcy; nie zastąpiono go krajem nabywcy.',
    ];

    public function present(array $report, SalesRegisterFilters $filters, array $options): array
    {
        $title = $filters->mode === 'ids' ? 'Rejestr faktur sprzedaży — wybrane dokumenty'
            : 'Rejestr faktur sprzedaży za okres '.$this->date($filters->issueFrom).' do '.$this->date($filters->issueTo);
        $description = $filters->mode === 'ids' ? 'Wybrane dokumenty: '.count($filters->documentIds) : implode(' · ', array_filter([
            'Serie (ID): '.implode(', ', $filters->seriesIds),
            'NIP: '.match ($filters->taxIdPresence) {
                'with' => 'z NIP', 'without' => 'bez NIP', default => 'wszystkie faktury'
            },
            $filters->saleFrom ? 'Sprzedaż od '.$this->date($filters->saleFrom) : null,
            $filters->saleTo ? 'Sprzedaż do '.$this->date($filters->saleTo) : null,
            $filters->currency ? 'Waluta: '.$filters->currency : null, $filters->country ? 'Kraj: '.$filters->country : null,
        ]));
        $labels = [];
        $countryNames = [];
        foreach ($report['records'] as &$record) {
            $labels[$record['id']] = $record['number'] ?? 'ID '.$record['id'];
            $record['buyer_lines'] = $this->buyerLines($record['buyer']);
            $code = $record['buyer']['country_code'];
            $name = $record['buyer']['country_name'];
            if ($code !== null && $name !== null && $name !== '') {
                $countryNames[$code][$name] = true;
            }
        }
        unset($record);
        $currencyKeys = array_keys($report['summaries']['currencies']);
        usort($currencyKeys, static fn ($a, $b) => ($b === 'PLN') <=> ($a === 'PLN') ?: strcmp($a, $b));
        $report['summaries']['currencies'] = array_replace(array_fill_keys($currencyKeys, null), $report['summaries']['currencies']);
        foreach ($report['summaries']['countries'] as $code => &$country) {
            $names = array_keys($countryNames[$code] ?? []);
            $country['label'] = $code === 'unknown' ? 'Nieustalony kraj' : (count($names) === 1 ? $names[0] : $code);
        }
        unset($country);

        return compact('report', 'options', 'title', 'description', 'labels') + ['presenter' => $this, 'columns' => $options['include_ksef'] ? 11 : 10];
    }

    public function money(?string $amount, ?string $currency): string
    {
        return $amount === null ? '—' : $amount.($currency !== null && $currency !== 'unknown' ? "\u{00A0}".$currency : '');
    }

    public function date(?string $date): string
    {
        return $date === null ? '—' : substr($date, 8, 2).'.'.substr($date, 5, 2).'.'.substr($date, 0, 4);
    }

    public function section(string $section): string
    {
        return self::SECTIONS[$section] ?? $section;
    }

    public function warning(string $code): string
    {
        return self::WARNINGS[str_replace('sales_register_', '', $code)] ?? 'Nieustalona kompletność danych ('.$code.').';
    }

    private function buyerLines(array $buyer): array
    {
        $a = $buyer['address'];
        $street = trim(($a['street'] ?? '').' '.($a['building_number'] ?? '').($a['apartment_number'] ? '/'.$a['apartment_number'] : ''));
        $city = trim(($a['postal_code'] ?? '').' '.($a['city'] ?? ''));
        $country = $buyer['country_name'] ?? $buyer['country_code'];

        return array_values(array_filter([$buyer['name'], $street, $city, $country, $buyer['tax_id']], static fn ($value) => $value !== null && $value !== ''));
    }
}
