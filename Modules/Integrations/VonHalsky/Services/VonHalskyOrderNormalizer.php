<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Support\AddressLineFormatter;
use App\Support\PhoneNumberFormatter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use JsonException;
use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;

class VonHalskyOrderNormalizer
{
    public function __construct(private readonly OrderImportVatResolver $vat,
        private readonly VonHalskyOrderCatalogService $catalog) {}

    public function normalize(VonHalskyAccount $account, array $data, array $quantities = []): array
    {
        foreach ($quantities as $quantity) {
            if ((! is_int($quantity) && ! is_string($quantity)) || ! preg_match('/\A[1-9][0-9]{0,5}\z/D', (string) $quantity)) {
                throw new VonHalskyApiException('Ilość pozycji musi być liczbą całkowitą od 1 do 999999.');
            }
        }
        $blockers = [];
        if ($data['status'] !== 'ACCEPTED') {
            $blockers[] = 'Import wymaga zaakceptowanego i opłaconego zamówienia.';
        }
        $total = $this->money($data['finalPrice'] ?? null);
        $delivery = $data['delivery'];
        $deliveryCost = $this->money($delivery['price'] ?? null);
        $currency = $data['finalPrice']['currency'] ?? null;
        if ($currency !== 'PLN' || ($delivery['price']['currency'] ?? null) !== $currency) {
            $blockers[] = 'Ten etap obsługuje wyłącznie spójne kwoty w PLN.';
        }
        $customer = $data['customer'] ?? [];
        $billing = filled($data['invoice'] ?? null) ? $data['invoice'] : $customer;
        $buyerName = trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? ''));
        $email = $customer['email'] ?? null;
        $phone = PhoneNumberFormatter::normalize($customer['phoneNumber'] ?? $delivery['phoneNumber'] ?? null);
        $shipping = $this->address($customer);
        if ($buyerName === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || blank($phone)) {
            $blockers[] = 'Brak pełnych danych kontaktowych kupującego.';
        }
        if (! in_array($delivery['deliveryType'], ['APM', 'COURIER'], true)) {
            $blockers[] = 'Nieobsługiwany typ dostawy. Sprawdź dane zamówienia.';
        }
        if ($delivery['deliveryType'] === 'APM') {
            if (blank($delivery['deliveryPoint'] ?? null)) {
                $blockers[] = 'Brak identyfikatora punktu odbioru.';
            }
        } else {
            $shipping = $this->address($delivery);
            if (blank($shipping['street']) || blank($shipping['building_number'])
                || blank($shipping['postal_code']) || blank($shipping['city'])) {
                $blockers[] = 'Brak pełnego adresu dostawy kurierskiej.';
            }
        }
        $country = $shipping['country_code'] ?: ($customer['address']['countryCode'] ?? null);
        if ($country !== 'PL') {
            $blockers[] = 'Import zagranicznego lub nieustalonego kraju wymaga osobnej obsługi.';
        }
        if (! in_array(strtoupper((string) ($billing['address']['countryCode'] ?? 'PL')), ['PL'], true)
            || ! in_array(strtoupper(trim((string) ($billing['taxIdPrefix'] ?? ''))), ['', 'PL'], true)) {
            $blockers[] = 'Zagraniczne dane do faktury wymagają osobnej obsługi VAT.';
        }
        $payments = $data['paymentDetails']['payments'];
        $payment = count($payments) === 1 ? $payments[0] : [];
        if (count($payments) !== 1 || blank($payment['paymentId'] ?? null) || blank($payment['paymentDate'] ?? null)
            || blank($payment['paymentType'] ?? null) || BigDecimal::of($total)->isLessThanOrEqualTo(0)) {
            $blockers[] = 'Brak jednoznacznego potwierdzenia pojedynczej zakończonej płatności w API.';
        }
        $initial = $account->initial_status_setting_id
            ? OrderStatusSetting::query()->find($account->initial_status_setting_id)
            : OrderStatusSetting::query()->where('status', Order::STATUS_NEW)->first();
        if (! $initial) {
            $blockers[] = 'Wybierz istniejący status początkowy importu.';
        }

        $items = [];
        $sum = BigDecimal::of($deliveryCost);
        $requiresQuantities = false;
        foreach ($data['orderLines'] as $index => $line) {
            $offer = $line['offer'];
            $price = $offer['finalPrice'] ?? $offer['price'] ?? null;
            $unit = $this->money($price);
            if (($price['currency'] ?? null) !== $currency) {
                $blockers[] = 'Waluta pozycji nie odpowiada walucie zamówienia.';
            }
            $provided = array_key_exists('quantity', $line);
            $quantity = $provided ? $line['quantity'] : ($quantities[$index] ?? null);
            if ($provided && $quantity === null) {
                throw new VonHalskyApiException('Von Halsky przekazał puste pole ilości. Sprawdź dane źródłowe.');
            }
            if ($quantity !== null && (! is_int($quantity) && ! is_string($quantity)
                || ! preg_match('/\A[1-9][0-9]{0,5}\z/D', (string) $quantity))) {
                throw new VonHalskyApiException('Ilość pozycji musi być liczbą całkowitą od 1 do 999999.');
            }
            if ($quantity === null) {
                $requiresQuantities = true;
            }
            $quantity = $quantity === null ? null : (int) $quantity;
            if ($provided && array_key_exists($index, $quantities) && (int) $quantities[$index] !== $quantity) {
                throw new VonHalskyApiException('Potwierdzona ilość różni się od ilości przekazanej przez API. Pobierz nowy podgląd.');
            }
            $lineTotal = $quantity === null ? null : (string) BigDecimal::of($unit)->multipliedBy($quantity)->toScale(2);
            if ($lineTotal !== null) {
                $sum = $sum->plus($lineTotal);
            }
            $rate = $this->vat->resolve(['status' => 'missing', 'rate' => null], null, false, false, $country === 'PL', $account->default_vat_rate);
            $items[] = [
                'external_id' => (string) $index,
                'product_name' => $offer['product']['name'],
                'offer_id' => is_string($offer['offerId'] ?? null) ? $offer['offerId'] : null,
                'quantity' => $quantity,
                'quantity_source' => $provided ? 'api' : 'manual',
                'unit_price_gross' => $unit,
                'total_price_gross' => $lineTotal,
                'currency' => $currency,
                'vat_rate' => $rate['rate'],
                'vat_source' => $rate['source'],
                'product_id' => null,
                'weight' => null,
            ];
            if (mb_strlen($offer['product']['name']) > 255) {
                $blockers[] = 'Nazwa produktu przekracza długość obsługiwaną przez OMS.';
            }
        }
        if (! $requiresQuantities && ! $sum->isEqualTo($total)) {
            $blockers[] = 'Suma cen × ilości i dostawy różni się od wartości zamówienia. Import został zablokowany.';
        }
        if (array_diff(array_keys($quantities), array_keys($data['orderLines'])) !== []) {
            throw new VonHalskyApiException('Potwierdzone ilości nie odpowiadają pozycjom podglądu.');
        }
        $billingAddress = $this->address($billing);
        $billingName = trim(($billing['firstName'] ?? '').' '.($billing['lastName'] ?? ''));
        $attributes = array_merge([
            'source' => 'von_halsky', 'external_id' => $data['id'],
            'status' => $initial?->status, 'customer_login' => null, 'customer_email' => $email,
            'customer_phone' => $phone, 'shipping_name' => $buyerName,
            'shipping_company_name' => null, 'shipping_phone' => $phone,
            'shipping_email' => $delivery['email'] ?? $email,
            'billing_name' => $billingName ?: $buyerName,
            'billing_company_name' => $billing['companyName'] ?? null,
            'billing_tax_id' => $billing['taxId'] ?? null,
            'billing_email' => $billing['email'] ?? $email, 'billing_phone' => $phone,
            'currency' => $currency, 'total_gross' => $total, 'paid_amount' => $total,
            'delivery_cost_gross' => $deliveryCost,
            'shipping_method' => $delivery['deliveryType'] === 'APM' ? 'InPost Paczkomat 24/7' : 'InPost Kurier Standard',
            'pickup_point_id' => $delivery['deliveryType'] === 'APM' ? ($delivery['deliveryPoint'] ?? null) : null,
            'pickup_point_name' => $delivery['deliveryType'] === 'APM' ? ($delivery['name'] ?? $delivery['deliveryPoint'] ?? null) : null,
            'pickup_point_address' => $delivery['deliveryType'] === 'APM'
                ? AddressLineFormatter::formatAddressLine($delivery['address']['street'] ?? null, $delivery['address']['building'] ?? null, $delivery['address']['flat'] ?? null) : null,
            'pickup_point_postal_code' => $delivery['deliveryType'] === 'APM' ? ($delivery['address']['postCode'] ?? null) : null,
            'pickup_point_city' => $delivery['deliveryType'] === 'APM' ? ($delivery['address']['city'] ?? null) : null,
            'cash_on_delivery' => false, 'payment_status' => 'paid',
            'payment_method' => $this->paymentLabel($payment['paymentType'] ?? ''),
            'purchased_at' => CarbonImmutable::parse($data['createdAt'])->setTimezone(config('app.timezone')),
            'paid_at' => isset($payment['paymentDate']) ? CarbonImmutable::parse($payment['paymentDate'])->setTimezone(config('app.timezone')) : null,
        ], $this->prefixed('shipping', $shipping), $this->prefixed('billing', $billingAddress));

        foreach ($attributes as $field => $value) {
            if (is_string($value) && mb_strlen($value) > 255) {
                $blockers[] = 'Dane zamówienia przekraczają długość obsługiwaną przez OMS.';
            }
        }

        $result = $this->catalog->apply($account, [
            'attributes' => $attributes, 'items' => $items, 'requires_quantities' => $requiresQuantities,
            'blockers' => array_values(array_unique($blockers)), 'importable' => $blockers === [] && ! $requiresQuantities,
            'invoice_required' => filled($data['invoice'] ?? null),
            'buyer_name' => $buyerName, 'buyer_message' => $data['comment'] ?? null,
            'external_status' => $data['status'], 'payment_evidence' => $payments,
        ]);
        $result['blockers'] = array_values(array_unique([...$result['blockers'], ...$result['catalog_blockers']]));
        $result['importable'] = $result['blockers'] === [] && ! $requiresQuantities;
        $result['source_hash'] = $this->hash($account, $data, $result['catalog_hash']);

        return $result;
    }

    private function money(mixed $value): string
    {
        $amount = is_array($value) ? ($value['amount'] ?? null) : null;
        if (! is_string($amount) && ! is_int($amount)
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/D', (string) $amount)) {
            throw new VonHalskyApiException('Import wymaga dokładnej, nieujemnej kwoty z najwyżej dwoma miejscami dziesiętnymi.');
        }
        $decimal = BigDecimal::of($amount)->toScale(2);
        if ($decimal->isGreaterThan('999999999.99')) {
            throw new VonHalskyApiException('Kwota zamówienia przekracza obsługiwany zakres.');
        }

        return (string) $decimal;
    }

    private function address(array $contact): array
    {
        $a = $contact['address'] ?? [];

        return ['street' => $a['street'] ?? null, 'building_number' => $a['building'] ?? null,
            'apartment_number' => $a['flat'] ?? null, 'postal_code' => $a['postCode'] ?? null,
            'city' => $a['city'] ?? null, 'province' => $a['state'] ?? null,
            'country_code' => isset($a['countryCode']) ? strtoupper($a['countryCode']) : null];
    }

    private function prefixed(string $prefix, array $address): array
    {
        $result = [];
        foreach ($address as $key => $value) {
            $result[$prefix.'_'.$key] = $value;
        }

        return $result;
    }

    private function hash(VonHalskyAccount $account, array $data, string $catalogHash): string
    {
        // Earlier previews confirmed import without automation events and must be refreshed.
        $context = ['import_version' => 2, 'account_id' => $account->id, 'organization_id' => $account->organization_id,
            'environment' => $account->environment->value, 'lock_version' => $account->lock_version,
            'connection_revision' => $account->connection_revision, 'initial_status_setting_id' => $account->initial_status_setting_id,
            'default_vat_rate' => $account->default_vat_rate, 'source' => $data, 'catalog_hash' => $catalogHash];

        try {
            return hash_hmac('sha256', json_encode($this->canonical($context), JSON_THROW_ON_ERROR), config('app.key'));
        } catch (JsonException) {
            throw new VonHalskyApiException('Nie można potwierdzić integralności danych Von Halsky.');
        }
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            }
        }

        return $data;
    }

    private function paymentLabel(string $type): string
    {
        return match ($type) {
            'BLIK_CODE' => 'BLIK', 'CARD' => 'Karta płatnicza', default => $type,
        };
    }
}
