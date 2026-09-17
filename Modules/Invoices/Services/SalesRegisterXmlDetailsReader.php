<?php

namespace Modules\Invoices\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

/** XML-only, batched enrichment of the exact records selected by the common register. */
final class SalesRegisterXmlDetailsReader
{
    public function __construct(private readonly SalesRegisterValues $values) {}

    public function read(array $records, bool $includeKsef): array
    {
        $documents = Invoice::query()->whereIn('id', array_column($records, 'id'))->get([
            'id', 'number', 'buyer_snapshot', 'buyer_name_snapshot', 'buyer_tax_id_snapshot',
            'order_reference_snapshot', 'order_snapshot', 'seller_snapshot', 'recipient_snapshot', 'payment_snapshot', 'correction_totals_snapshot',
        ])->keyBy('id');
        $documents->load(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')->select([
            'id', 'invoice_id', 'product_id', 'position', 'line_type', 'name', 'quantity', 'unit_price_gross',
            'vat_rate', 'vat_code', 'total_net', 'total_vat', 'total_gross', 'product_snapshot',
            'correction_before_snapshot', 'correction_after_snapshot',
        ])]);
        $result = [];
        foreach ($records as $record) {
            $document = $documents->get($record['id']);
            if ($document === null) {
                $this->invalid($record['id'], 'document', 'Dokument nie jest już dostępny.');
            }
            $result[] = $this->map($record, $document, $includeKsef);
        }

        return $result;
    }

    private function map(array $record, Invoice $document, bool $includeKsef): array
    {
        $id = $record['id'];
        $correction = $record['type'] === 'correction';
        $warnings = [];
        // Validate original text before the shared reader's whitespace normalization can hide invalid XML characters.
        foreach (['number', 'buyer_name_snapshot', 'buyer_tax_id_snapshot'] as $field) {
            $this->text($document->getRawOriginal($field), $id, $field);
        }
        $rawPayment = data_get($document->payment_snapshot, 'effective_payment_method');
        if (is_string($rawPayment)) {
            $this->assertXmlText($rawPayment, $id, 'payment');
        }
        $buyerSnapshot = is_array($document->buyer_snapshot) ? $document->buyer_snapshot : [];
        foreach (['name', 'company_name', 'tax_id', 'street', 'building_number', 'apartment_number', 'postal_code', 'city', 'province', 'country_code', 'country_name'] as $field) {
            $this->text($buyerSnapshot[$field] ?? null, $id, 'buyer.'.$field);
        }
        $fields = ['no' => (string) $record['ordinal'],
            'invoice_number' => ($correction ? 'Korekta ' : 'Faktura ').$this->text($record['number'], $id, 'number', true)];
        if ($correction) {
            $numbers = [];
            foreach ([data_get($document->order_snapshot, 'corrected_invoice.number'),
                data_get($document->order_snapshot, 'related_documents.invoice.number'),
                data_get($document->correction_totals_snapshot, 'source_invoice.number')] as $number) {
                $number = $this->text($number, $id, 'correction_to_invoice_number');
                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
            $numbers = array_values(array_unique($numbers));
            if (count($numbers) !== 1) {
                $this->invalid($id, 'correction_to_invoice_number', 'Brak jednoznacznego historycznego numeru Faktury źródłowej.');
            }
            $fields['correction_to_invoice_number'] = $numbers[0];
            foreach ($record['warnings'] as $warning) {
                if (in_array($warning['code'], ['sales_register_correction_difference_missing', 'sales_register_correction_tax_difference_missing'], true)) {
                    $this->invalid($id, 'correction.difference', 'Brak kompletnego snapshotu różnicy Korekty.');
                }
            }
        }
        if ($includeKsef) {
            $fields['ksef_id'] = $record['ksef_number'];
        }
        foreach (['date_invoice' => 'issue_date', 'date_sell' => 'sale_date'] as $xml => $field) {
            $date = $this->values->date($record[$field]);
            if ($date === null) {
                $this->invalid($id, $field, 'Brak prawidłowej daty dokumentu.');
            }
            $fields[$xml] = substr($date, 8, 2).'.'.substr($date, 5, 2).'.'.substr($date, 0, 4);
        }
        $order = is_array($document->order_snapshot) ? $document->order_snapshot : [];
        $reference = $this->identifier($document->order_reference_snapshot, $id, 'order_id');
        $orderId = $this->identifier($order['id'] ?? null, $id, 'order_snapshot.id');
        if ($reference !== '' && $orderId !== '' && $reference !== $orderId) {
            $reference = $orderId = '';
            $warnings[] = $this->warning($id, 'order_id_conflict');
        }
        $fields['order_id'] = $reference !== '' ? $reference : $orderId;
        $fields['shop_order_id'] = ($order['source'] ?? null) === 'prestashop'
            ? $this->identifier($order['external_id'] ?? null, $id, 'shop_order_id') : '';
        foreach (['order_id', 'shop_order_id'] as $field) {
            if ($fields[$field] === '') {
                $warnings[] = $this->warning($id, $field.'_unavailable');
            }
        }
        $buyer = $record['buyer'];
        $fields['client_information'] = [
            'invoice_fullname' => $buyer['name'], 'invoice_address' => $this->address($buyer['address'], $id, 'buyer'),
            'invoice_postcode' => $buyer['address']['postal_code'], 'invoice_city' => $buyer['address']['city'],
            'invoice_state' => $buyer['address']['province'], 'invoice_country' => $buyer['country_name'] ?? $buyer['country_code'],
            'invoice_nip' => $buyer['tax_id'],
        ];
        $seller = is_array($document->seller_snapshot) ? $document->seller_snapshot : [];
        $sellerLines = [$this->text($seller['name'] ?? null, $id, 'seller.name'), $this->address($seller, $id, 'seller'),
            trim($this->text($seller['postal_code'] ?? null, $id, 'seller.postal_code').' '.$this->text($seller['city'] ?? null, $id, 'seller.city'))];
        foreach (['tax_id' => 'NIP: ', 'bdo' => 'BDO: '] as $key => $prefix) {
            $value = $this->text($seller[$key] ?? null, $id, 'seller.'.$key);
            if ($value !== '') {
                $sellerLines[] = $prefix.$value;
            }
        }
        $fields['seller_information'] = ['fv_seller' => implode("\n", array_filter($sellerLines, static fn ($v) => $v !== ''))];
        if ($fields['seller_information']['fv_seller'] === '') {
            $warnings[] = $this->warning($id, 'seller_unavailable');
        }
        $items = $original = $rates = [];
        if ($document->items->isEmpty()) {
            $this->invalid($id, 'items', 'Brak zapisanych pozycji dokumentu.');
        }
        foreach ($document->items as $item) {
            $path = 'items.'.$item->id;
            $after = $correction ? $item->correction_after_snapshot : $item->getAttributes();
            $mapped = $this->item($after, $id, $path.'.after', $rates);
            $product = is_array($item->product_snapshot) ? $item->product_snapshot : [];
            $productId = ($after['line_type'] ?? null) !== 'shipping' ? $this->identifier($item->getRawOriginal('product_id'), $id, $path.'.product_id') : '';
            $sku = ($after['line_type'] ?? null) !== 'shipping' ? $this->text($product['sku'] ?? null, $id, $path.'.sku') : '';
            if ($productId === '' || $sku === '') {
                $warnings[] = $this->warning($id, 'item_identifiers_unavailable');
            }
            $items[] = ['item_product_id' => $productId, 'item_name' => $mapped['item_name'], 'item_sku' => $sku]
                + array_diff_key($mapped, ['item_name' => true]) + ['item_tax' => $this->compact($mapped['item_tax_rate']).'%'];
            if ($correction) {
                $original[] = $this->item($item->correction_before_snapshot, $id, $path.'.before', $rates);
            }
        }
        $fields['items'] = ['item' => $items];
        if ($correction) {
            $fields['original_items'] = ['item' => $original];
        }
        // NEX currently persists order metadata, not the original complete order-item collection.
        $fields['order_items'] = [];
        $warnings[] = $this->warning($id, 'order_items_unavailable');
        $fields['payment'] = $record['payment_method'];
        $fields['currency'] = $this->text($record['currency'], $id, 'currency', true);
        $fields['currency_calc_rate'] = $record['currency'] === 'PLN' ? '0.000000' : ($record['exchange_rate']['rate'] ?? '');
        if ($fields['currency_calc_rate'] === '') {
            $warnings[] = ['code' => 'sales_register_exchange_rate_unavailable', 'document_id' => $id, 'section' => 'exchange_rate'];
        }
        $country = $this->text(data_get($document->recipient_snapshot, 'country_code'), $id, 'receiver_country_code');
        if (! preg_match('/^[A-Za-z]{2}$/D', $country)) {
            $country = '';
            $warnings[] = $this->warning($id, 'recipient_country_unavailable');
        }
        $fields['receiver_country_code'] = strtoupper($country);
        if ($record['totals'] === null || $record['vat_groups'] === null) {
            $this->invalid($id, 'totals/vat_groups', 'Brak poprawnych kwot lub podsumowania VAT.');
        }
        foreach ($record['vat_groups'] as $group) {
            $rates[] = $this->rate($group, $id, 'tax');
        }
        $rates = array_values(array_unique($rates));
        if (count($rates) !== 1) {
            $this->invalid($id, 'tax', 'Profil XML nie określa mapowania dokumentu z wieloma stawkami VAT.');
        }
        $fields['total_price_netto'] = $this->decimal($record['totals']['net'], 2, $id, 'total_price_netto', true);
        $fields['tax'] = $correction ? $rates[0] : $this->compact($rates[0]);
        $fields['total_tax'] = $this->decimal($record['totals']['vat'], 2, $id, 'total_tax', true);
        $fields['total_price_brutto'] = $this->decimal($record['totals']['gross'], 2, $id, 'total_price_brutto', true);

        return ['id' => $id, 'fields' => $fields, 'warnings' => $warnings];
    }

    private function item(mixed $state, int $id, string $path, array &$rates): array
    {
        if (! is_array($state) || ! in_array($state['line_type'] ?? null, ['product', 'shipping', 'custom'], true)) {
            $this->invalid($id, $path, 'Brak kompletnego zapisanego stanu pozycji.');
        }
        if ($this->values->totals($state, 'total_') === null) {
            $this->invalid($id, $path.'.totals', 'Brak poprawnych kwot stanu pozycji.');
        }
        $rate = $this->rate($state, $id, $path.'.vat');
        $rates[] = $rate;
        $name = $this->text($state['name'] ?? null, $id, $path.'.name', true);
        if ($state['line_type'] === 'shipping' && ! str_starts_with($name, 'Przesyłka: ')) {
            $name = 'Przesyłka: '.$this->compact($rate).'% VAT: '.$name;
        }

        return ['item_name' => $name,
            'item_quantity' => $this->compact($this->decimal($state['quantity'] ?? null, 4, $id, $path.'.quantity')),
            'item_price_brutto' => $this->decimal($state['unit_price_gross'] ?? null, 4, $id, $path.'.unit_price_gross'),
            'item_tax_rate' => $rate];
    }

    private function rate(array $state, int $id, string $path): string
    {
        if (($state['vat_code'] ?? null) !== null && ($state['vat_code'] ?? null) !== '') {
            $this->invalid($id, $path, 'Profil XML nie określa mapowania kodów VAT (np. ZW).');
        }
        $rate = $this->decimal($state['vat_rate'] ?? null, 2, $id, $path);
        if (BigDecimal::of($rate)->isGreaterThan(100)) {
            $this->invalid($id, $path, 'Nieprawidłowa stawka VAT.');
        }

        return $rate;
    }

    private function decimal(mixed $value, int $scale, int $id, string $path, bool $signed = false): string
    {
        // PDO SQLite numeric scalars are converted to text, never used for financial arithmetic.
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || ! preg_match('/^'.($signed ? '-?' : '').'\d+(?:\.\d+)?$/D', (string) $value)) {
            $this->invalid($id, $path, 'Brak prawidłowej wartości dziesiętnej.');
        }
        try {
            return (string) BigDecimal::of((string) $value)->toScale($scale);
        } catch (MathException) {
            $this->invalid($id, $path, 'Wartość przekracza obsługiwaną precyzję; nie została zaokrąglona.');
        }
    }

    private function compact(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function identifier(mixed $value, int $id, string $field): string
    {
        return $this->text(is_int($value) ? (string) $value : $value, $id, $field);
    }

    private function text(mixed $value, int $id, string $field, bool $required = false): string
    {
        if ($value === null && ! $required) {
            return '';
        }
        if (! is_string($value) || ($required && trim($value) === '')) {
            $this->invalid($id, $field, 'Brak prawidłowego tekstu.');
        }
        $this->assertXmlText($value, $id, $field);

        return $value;
    }

    public function assertXmlText(string $value, int $id, string $field): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value)) {
            $this->invalid($id, $field, 'Tekst nie jest poprawnym UTF-8/XML 1.0.');
        }
    }

    private function address(array $data, int $id, string $path): string
    {
        $street = $this->text($data['street'] ?? null, $id, $path.'.street');
        $building = $this->text($data['building_number'] ?? null, $id, $path.'.building_number');
        $apartment = $this->text($data['apartment_number'] ?? null, $id, $path.'.apartment_number');

        return trim($street.' '.$building.($apartment !== '' ? '/'.$apartment : ''));
    }

    private function warning(int $id, string $code): array
    {
        return ['code' => 'sales_register_xml_'.$code, 'document_id' => $id, 'section' => 'document'];
    }

    private function invalid(int $id, string $field, string $message): never
    {
        throw new InvoiceDomainException('sales_register_xml_invalid', 'Dokument ID '.$id.', pole '.$field.': '.$message);
    }
}
