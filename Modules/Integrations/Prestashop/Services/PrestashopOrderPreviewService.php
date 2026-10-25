<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Exceptions\OrderPaymentStateException;
use App\Services\OrderPaymentStateService;
use App\Support\PhoneNumberFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use Throwable;

class PrestashopOrderPreviewService
{
    public function __construct(
        private readonly PrestashopClient $client,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceFinancialValueValidator $financial,
        private readonly OrderPaymentStateService $paymentState,
    ) {}

    /** @return array<string, mixed> */
    public function preview(PrestashopAccount $account, string $identifier): array
    {
        $this->assertConnectionReady($account);

        $remoteOrder = $this->client->findOrder($account, $identifier);
        $externalOrderId = $this->positiveId($remoteOrder['id'] ?? null, 'zamówienia');
        $reference = $this->requiredText($remoteOrder['reference'] ?? null, 'numeru referencyjnego', 64);
        $enrichment = $this->client->orderEnrichment($account, $externalOrderId);
        $pickupPoint = $this->pickupPoint($enrichment);
        $details = $this->client->orderDetails($account, $externalOrderId);

        if ($details === []) {
            throw new PrestashopImportException('Zamówienie PrestaShop nie zawiera pozycji i nie może zostać zaimportowane.');
        }

        $currencyRecord = $this->client->currency(
            $account,
            $this->positiveId($remoteOrder['id_currency'] ?? null, 'waluty'),
        );
        $currency = strtoupper($this->requiredText($currencyRecord['iso_code'] ?? null, 'waluty', 3));
        $this->assertCurrencyAvailable($account, $currency);

        $customer = $this->client->customer(
            $account,
            $this->positiveId($remoteOrder['id_customer'] ?? null, 'klienta'),
        );
        $shippingAddress = $this->address(
            $account,
            $this->positiveId($remoteOrder['id_address_delivery'] ?? null, 'adresu dostawy'),
            $customer,
            false,
        );
        $billingAddress = $this->address(
            $account,
            $this->positiveId($remoteOrder['id_address_invoice'] ?? null, 'adresu rozliczeniowego'),
            $customer,
            true,
        );

        $carrierId = $this->optionalId($remoteOrder['id_carrier'] ?? null);
        $carrier = $carrierId ? $this->client->carrier($account, $carrierId) : [];
        $items = array_map(fn (array $detail): array => $this->item($detail, $currency), $details);
        $totalGross = $this->money($remoteOrder['total_paid_tax_incl'] ?? $remoteOrder['total_paid'] ?? null, 'wartości zamówienia');
        $deliveryCostGross = $this->money($remoteOrder['total_shipping_tax_incl'] ?? '0', 'kosztu dostawy');
        $this->assertSupportedTotals($remoteOrder, $items, $deliveryCostGross, $totalGross, $currency);

        $payments = $this->payments($account, $reference, $currency);
        $paidAmount = $this->money($remoteOrder['total_paid_real'] ?? '0', 'kwoty zapłaconej');
        $this->assertPaymentsMatch($payments, $paidAmount, $currency);
        $paymentStatus = $this->paymentStatus($totalGross, $paidAmount);
        $currentStateId = $this->orderStateId($remoteOrder['current_state'] ?? null);
        $currentState = $currentStateId === null
            ? null
            : $account->orderStates()->where('external_id', $currentStateId)->first();

        if ($currentStateId !== null && ! $currentState) {
            throw new PrestashopImportException(
                'Status zamówienia nie występuje w zapisanej konfiguracji. Wykonaj ponownie test połączenia.',
            );
        }
        $warnings = $this->warnings($shippingAddress, $billingAddress, $paidAmount, $payments, $remoteOrder, $pickupPoint);

        if ($currentStateId === null) {
            $warnings[] = 'PrestaShop nie przypisał statusu. Zamówienie zostanie zaimportowane do OMS jako Nowe.';
        }

        $preview = [
            'external_order_id' => $externalOrderId,
            'reference' => $reference,
            'external_status_id' => $currentStateId,
            'external_status_name' => $currentState?->name,
            'customer_login' => null,
            'customer_email' => $this->nullableText($customer['email'] ?? null, 255),
            'customer_phone' => $shippingAddress['phone'] ?: $billingAddress['phone'],
            'shipping_address' => $shippingAddress,
            'billing_address' => $billingAddress,
            'currency' => $currency,
            'total_gross' => $totalGross,
            'paid_amount' => $paidAmount,
            'delivery_cost_gross' => $deliveryCostGross,
            'shipping_method' => $this->nullableText($carrier['name'] ?? null, 255),
            'pickup_point' => $pickupPoint,
            'cash_on_delivery' => $this->cashOnDelivery($remoteOrder),
            'payment_status' => $paymentStatus,
            'payment_method' => $this->nullableText($remoteOrder['payment'] ?? null, 255),
            'purchased_at' => $this->date($remoteOrder['date_add'] ?? null, 'daty zamówienia'),
            'paid_at' => $this->paidAt($payments, $paidAmount),
            'external_updated_at' => $this->date($remoteOrder['date_upd'] ?? null, 'daty aktualizacji'),
            'notes' => $this->notes($remoteOrder),
            'items' => $items,
            'payments' => $payments,
            'warnings' => $warnings,
        ];

        $preview['source_hash'] = $this->sourceHash($preview);
        $link = PrestashopOrderLink::query()
            ->where('prestashop_account_id', $account->getKey())
            ->where('external_order_id', $externalOrderId)
            ->first();
        $existingOrder = $link?->order;
        $preview['existing_order_id'] = $existingOrder?->getKey();
        $preview['existing_order_trashed'] = (bool) $existingOrder?->trashed();

        return $preview;
    }

    private function assertConnectionReady(PrestashopAccount $account): void
    {
        if (! $account->hasCompleteCredentials()
            || $account->last_test_status !== PrestashopConnectionTestStatus::Success) {
            throw new PrestashopImportException(
                'Najpierw zapisz konfigurację i wykonaj poprawny test połączenia z PrestaShop.',
            );
        }
    }

    private function assertCurrencyAvailable(PrestashopAccount $account, string $currency): void
    {
        $available = collect($account->available_currencies ?? [])
            ->pluck('iso_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! $available->contains($currency)) {
            throw new PrestashopImportException("Waluta {$currency} nie jest aktywna w wykrytej konfiguracji PrestaShop.");
        }
    }

    /** @return array<string, ?string> */
    private function address(
        PrestashopAccount $account,
        int $addressId,
        array $customer,
        bool $billing,
    ): array {
        $address = $this->client->address($account, $addressId);
        $country = $this->client->country(
            $account,
            $this->positiveId($address['id_country'] ?? null, 'kraju'),
        );
        $stateId = $this->optionalId($address['id_state'] ?? null);
        $state = $stateId ? $this->client->state($account, $stateId) : [];
        $streetParts = array_values(array_filter([
            $this->nullableText($address['address1'] ?? null, 255),
            $this->nullableText($address['address2'] ?? null, 255),
        ]));
        $phone = $this->firstText([
            $address['phone_mobile'] ?? null,
            $address['phone'] ?? null,
        ], 255);

        return [
            'name' => $this->personName($address),
            'company_name' => $this->nullableText($address['company'] ?? null, 255),
            'tax_id' => $billing ? $this->nullableText($address['vat_number'] ?? null, 255) : null,
            'street' => $this->nullableText(implode(', ', $streetParts), 255),
            'building_number' => null,
            'apartment_number' => null,
            'postal_code' => $this->nullableText($address['postcode'] ?? null, 255),
            'city' => $this->nullableText($address['city'] ?? null, 255),
            'province' => $this->nullableText($state['name'] ?? null, 255),
            'country_code' => strtoupper($this->requiredText($country['iso_code'] ?? null, 'kraju', 2)),
            'phone' => PhoneNumberFormatter::normalize($phone),
            'email' => $this->nullableText($customer['email'] ?? null, 255),
        ];
    }

    /** @return array<string, mixed> */
    private function item(array $detail, string $currency): array
    {
        $quantity = $this->positiveInt($detail['product_quantity'] ?? null, 'ilości produktu');
        $unitGross = $this->money($detail['unit_price_tax_incl'] ?? null, 'ceny produktu');
        $totalGross = $this->money(
            $detail['total_price_tax_incl'] ?? $this->decimal->multiply($unitGross, $quantity),
            'wartości pozycji',
        );
        $rawNet = $detail['unit_price_tax_excl'] ?? null;
        $unitNet = $rawNet === null || $rawNet === '' ? null : $this->money($rawNet, 'ceny netto produktu');
        $vatRate = $unitNet === null ? null : $this->vatRate($unitGross, $unitNet);
        $rawTotalNet = $detail['total_price_tax_excl'] ?? null;
        if ($vatRate === null && $rawTotalNet !== null && $rawTotalNet !== '') {
            $vatRate = $this->vatRate($totalGross, $this->money($rawTotalNet, 'wartości netto pozycji'));
        }

        return [
            'external_order_detail_id' => $this->positiveId($detail['id'] ?? null, 'pozycji zamówienia'),
            'external_product_id' => $this->optionalId($detail['product_id'] ?? null),
            'external_product_attribute_id' => $this->optionalId($detail['product_attribute_id'] ?? null),
            'product_name' => $this->requiredText($detail['product_name'] ?? null, 'nazwy produktu', 255),
            'sku' => $this->nullableText($detail['product_reference'] ?? null, 255),
            'ean' => $this->nullableText($detail['product_ean13'] ?? null, 255),
            'quantity' => $quantity,
            'unit_price_gross' => $unitGross,
            'total_price_gross' => $totalGross,
            'currency' => $currency,
            'vat_rate' => $vatRate,
            'weight' => $this->weight($detail['product_weight'] ?? null),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function payments(
        PrestashopAccount $account,
        string $reference,
        string $orderCurrency,
    ): array {
        return array_map(
            fn (array $payment): array => $this->payment($account, $payment, $orderCurrency),
            $this->client->orderPayments($account, $reference),
        );
    }

    /** @return array<string, mixed> */
    private function payment(PrestashopAccount $account, array $payment, string $orderCurrency): array
    {
        $currencyId = $this->optionalId($payment['id_currency'] ?? null);
        $currency = $orderCurrency;

        if ($currencyId) {
            $currencyRecord = $this->client->currency($account, $currencyId);
            $currency = strtoupper($this->requiredText($currencyRecord['iso_code'] ?? null, 'waluty płatności', 3));
        }

        if ($currency !== $orderCurrency) {
            throw new PrestashopImportException('Waluta płatności jest inna niż waluta zamówienia PrestaShop.');
        }

        return [
            'external_payment_id' => $this->positiveId($payment['id'] ?? null, 'płatności'),
            'transaction_id' => $this->nullableText($payment['transaction_id'] ?? null, 191),
            'amount' => $this->money($payment['amount'] ?? null, 'płatności'),
            'currency' => $currency,
            'method' => $this->nullableText($payment['payment_method'] ?? null, 255),
            'paid_at' => $this->optionalDate($payment['date_add'] ?? null),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function assertSupportedTotals(
        array $remoteOrder,
        array $items,
        string $deliveryCostGross,
        string $totalGross,
        string $currency,
    ): void {
        $discount = $this->money($remoteOrder['total_discounts_tax_incl'] ?? '0', 'rabatu');
        $wrapping = $this->money($remoteOrder['total_wrapping_tax_incl'] ?? '0', 'opakowania prezentowego');

        if ($this->decimal->compare($discount, '0.00') !== 0 || $this->decimal->compare($wrapping, '0.00') !== 0) {
            throw new PrestashopImportException(
                'Zamówienie zawiera rabat koszykowy lub opakowanie prezentowe, których ten etap importu jeszcze nie obsługuje.',
            );
        }

        $calculated = $deliveryCostGross;

        foreach ($items as $item) {
            $calculated = $this->decimal->add($calculated, $item['total_price_gross']);
        }

        if ($this->decimal->compare($calculated, $totalGross) !== 0) {
            throw new PrestashopImportException(
                "Suma pozycji i dostawy ({$calculated} {$currency}) nie zgadza się z wartością zamówienia ({$totalGross} {$currency}).",
            );
        }
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function assertPaymentsMatch(array $payments, string $paidAmount, string $currency): void
    {
        if ($payments === []) {
            return;
        }

        $paymentTotal = '0.00';

        foreach ($payments as $payment) {
            $paymentTotal = $this->decimal->add($paymentTotal, $payment['amount']);
        }

        if ($this->decimal->compare($paymentTotal, $paidAmount) !== 0) {
            throw new PrestashopImportException(
                "Suma płatności ({$paymentTotal} {$currency}) nie zgadza się z kwotą zapłaconą ({$paidAmount} {$currency}).",
            );
        }
    }

    private function paymentStatus(string $totalGross, string $paidAmount): string
    {
        $status = $this->decimal->compare($totalGross, '0.00') > 0
            && $this->decimal->compare($paidAmount, $totalGross) === 0
                ? OrderPaymentStateService::STATUS_PAID
                : OrderPaymentStateService::STATUS_UNPAID;

        try {
            return $this->paymentState->explicit($totalGross, $paidAmount, $status)['payment_status'];
        } catch (OrderPaymentStateException $exception) {
            throw new PrestashopImportException($exception->getMessage(), previous: $exception);
        }
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function paidAt(array $payments, string $paidAmount): ?string
    {
        if ($this->decimal->compare($paidAmount, '0.00') === 0) {
            return null;
        }

        return collect($payments)->pluck('paid_at')->filter()->sort()->last();
    }

    /** @return array<int, string> */
    private function warnings(
        array $shipping,
        array $billing,
        string $paidAmount,
        array $payments,
        array $remoteOrder,
        array $pickupPoint,
    ): array {
        $warnings = [];

        if (! $shipping['email']) {
            $warnings[] = 'Brak adresu e-mail klienta.';
        }

        if (! $shipping['phone'] && ! $billing['phone']) {
            $warnings[] = 'Brak numeru telefonu klienta.';
        }

        if ($this->decimal->compare($paidAmount, '0.00') > 0 && $payments === []) {
            $warnings[] = 'PrestaShop podaje wpłatę, ale nie zwrócił szczegółowego rekordu płatności.';
        }

        if (str_contains(mb_strtolower((string) ($remoteOrder['module'] ?? '')), 'inpost')
            && ! $pickupPoint['id']) {
            $warnings[] = 'PrestaShop nie udostępnił danych punktu odbioru dla tej przesyłki.';
        }

        return $warnings;
    }

    /** @return array{name: ?string, id: ?string, address: ?string, postal_code: ?string, city: ?string} */
    private function pickupPoint(array $enrichment): array
    {
        $apaczkaPoint = $this->nullableText($enrichment['apaczka_point'] ?? null, 255);
        $id = $apaczkaPoint ?: $this->nullableText($enrichment['bl_delivery_point_id'] ?? null, 255);
        $name = $apaczkaPoint ?: $this->nullableText($enrichment['bl_delivery_point_name'] ?? null, 255);

        return [
            'name' => $name ?: $id,
            'id' => $id ?: $name,
            'address' => $this->nullableText($enrichment['bl_delivery_point_address'] ?? null, 255),
            'postal_code' => $this->nullableText($enrichment['bl_delivery_point_postcode'] ?? null, 255),
            'city' => $this->nullableText($enrichment['bl_delivery_point_city'] ?? null, 255),
        ];
    }

    private function cashOnDelivery(array $remoteOrder): bool
    {
        $value = mb_strtolower(trim(implode(' ', [
            (string) ($remoteOrder['payment'] ?? ''),
            (string) ($remoteOrder['module'] ?? ''),
        ])));

        return str_contains($value, 'pobrani')
            || str_contains($value, 'cash on delivery')
            || str_contains($value, 'cashondelivery');
    }

    private function notes(array $remoteOrder): ?string
    {
        $notes = array_values(array_filter([
            $this->nullableText($remoteOrder['note'] ?? null, 4000),
            $this->nullableText($remoteOrder['gift_message'] ?? null, 4000),
        ]));

        return $notes === [] ? null : implode(PHP_EOL, $notes);
    }

    private function personName(array $record): ?string
    {
        return $this->nullableText(trim(implode(' ', array_filter([
            $this->nullableText($record['firstname'] ?? null, 255),
            $this->nullableText($record['lastname'] ?? null, 255),
        ]))), 255);
    }

    private function money(mixed $value, string $field): string
    {
        try {
            $normalized = $this->decimal->normalize((string) $value, 2);

            if ($this->decimal->compare($normalized, '0.00') < 0) {
                throw new PrestashopImportException("PrestaShop zwrócił ujemną wartość {$field}.");
            }

            return $this->financial->assertOrderMoney(
                $normalized,
                "Wartość {$field} przekracza zakres obsługiwany przez OMS.",
            );
        } catch (InvoiceDomainException $exception) {
            throw new PrestashopImportException($exception->getMessage(), previous: $exception);
        }
    }

    private function vatRate(string $gross, string $net): ?string
    {
        try {
            $grossValue = BigDecimal::of($gross);
            $netValue = BigDecimal::of($net);

            if ($grossValue->isZero() && $netValue->isZero()) {
                return null;
            }

            if ($netValue->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new PrestashopImportException('Nie można wyznaczyć stawki VAT pozycji zamówienia.');
            }

            $rate = $grossValue
                ->dividedBy($netValue, 8, RoundingMode::HalfUp)
                ->minus(1)
                ->multipliedBy(100)
                ->toScale(2, RoundingMode::HalfUp);

            if ($rate->isNegative() || $rate->isGreaterThan(BigDecimal::of(100))) {
                throw new PrestashopImportException('PrestaShop zwrócił nieprawidłową stawkę VAT pozycji.');
            }

            return (string) $rate;
        } catch (PrestashopImportException $exception) {
            throw $exception;
        } catch (MathException|\InvalidArgumentException $exception) {
            throw new PrestashopImportException('Nie można wyznaczyć stawki VAT pozycji zamówienia.', previous: $exception);
        }
    }

    private function weight(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $weight = $this->decimal->normalize((string) $value, 3);

            if ($this->decimal->compare($weight, '0.000', 3) < 0
                || $this->decimal->compare($weight, '9999999.999', 3) > 0) {
                throw new PrestashopImportException('Waga produktu przekracza zakres obsługiwany przez OMS.');
            }

            return $weight;
        } catch (InvoiceDomainException $exception) {
            throw new PrestashopImportException($exception->getMessage(), previous: $exception);
        }
    }

    private function date(mixed $value, string $field): string
    {
        $date = $this->optionalDate($value);

        if ($date === null) {
            throw new PrestashopImportException("PrestaShop nie zwrócił prawidłowej {$field}.");
        }

        return $date;
    }

    private function optionalDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = $this->optionalId($value);

        if ($id === null) {
            throw new PrestashopImportException("PrestaShop zwrócił nieprawidłowe ID {$field}.");
        }

        return $id;
    }

    private function orderStateId(mixed $value): ?int
    {
        if ($value === 0 || $value === '0') {
            return null;
        }

        return $this->positiveId($value, 'statusu zamówienia');
    }

    private function optionalId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($integer === false) {
            throw new PrestashopImportException("PrestaShop zwrócił nieprawidłową wartość {$field}.");
        }

        return (int) $integer;
    }

    private function requiredText(mixed $value, string $field, int $maxLength): string
    {
        $text = $this->nullableText($value, $maxLength);

        if ($text === null) {
            throw new PrestashopImportException("PrestaShop nie zwrócił {$field}.");
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    /** @param array<int, mixed> $values */
    private function firstText(array $values, int $maxLength): ?string
    {
        foreach ($values as $value) {
            $text = $this->nullableText($value, $maxLength);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function sourceHash(array $preview): string
    {
        unset($preview['warnings']);
        $this->sortRecursively($preview);

        return hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
