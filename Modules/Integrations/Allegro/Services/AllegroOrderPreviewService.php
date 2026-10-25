<?php

namespace Modules\Integrations\Allegro\Services;

use App\Exceptions\OrderPaymentStateException;
use App\Models\Order;
use App\Services\OrderPaymentStateService;
use App\Support\PhoneNumberFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Support\AllegroPaymentProvider;
use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use Throwable;

class AllegroOrderPreviewService
{
    public function __construct(
        private readonly AllegroClient $client,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceFinancialValueValidator $financial,
        private readonly OrderPaymentStateService $paymentState,
        private readonly AllegroOrderCatalogService $catalog,
        private readonly AllegroOrderInformationService $information,
        private readonly OrderImportVatResolver $vat,
    ) {}

    public function preview(AllegroAccount $account, string $identifier): array
    {
        $raw = $this->client->order($account, $identifier);

        return $this->normalize($account, $identifier, $raw);
    }

    public function normalize(AllegroAccount $account, string $identifier, array $raw): array
    {

        if (! is_string($raw['id'] ?? null) || ! Str::isUuid($raw['id'])
            || strtolower($raw['id']) !== strtolower($identifier)) {
            throw new AllegroImportException('Allegro zwróciło nieprawidłowy identyfikator zamówienia.');
        }

        $blockers = [];
        $warnings = [];
        $status = $this->text($raw['status'] ?? null, 50) ?? 'UNKNOWN';

        if ($status !== 'READY_FOR_PROCESSING') {
            $blockers[] = 'Import wymaga kompletnego zamówienia READY_FOR_PROCESSING. Aktualny status Allegro: '.$status.'.';
        }

        if (data_get($raw, 'fulfillment.provider.id') !== 'SELLER') {
            $blockers[] = 'Import wymaga zamówienia realizowanego przez sprzedawcę. Inny lub nieznany sposób realizacji wymaga odrębnej obsługi.';
        }

        $currency = strtoupper($this->text(data_get($raw, 'summary.totalToPay.currency'), 3) ?? '');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new AllegroImportException('Allegro nie zwróciło prawidłowej waluty zamówienia.');
        }

        $total = $this->money(data_get($raw, 'summary.totalToPay'), $currency);
        $delivery = data_get($raw, 'delivery.cost');
        $deliveryCost = $this->money($delivery, $currency);
        $items = $this->items((array) ($raw['lineItems'] ?? []), $currency);
        if ($items === []) {
            $blockers[] = 'Zamówienie nie zawiera produktów.';
        }

        $itemTotal = '0.00';
        foreach ($items as $item) {
            $itemTotal = $this->decimal->add($itemTotal, $item['total_price_gross']);
        }
        if ($this->decimal->compare($this->decimal->add($itemTotal, $deliveryCost), $total) !== 0) {
            $blockers[] = 'Suma produktów, usług dodatkowych i dostawy różni się od kwoty zamówienia Allegro. To rozliczenie wymaga sprawdzenia.';
        }

        $paymentType = $this->text(data_get($raw, 'payment.type'), 40) ?? 'UNKNOWN';
        $provider = $this->text(data_get($raw, 'payment.provider'), 60);
        $cashOnDelivery = $paymentType === 'CASH_ON_DELIVERY';
        if (! in_array($paymentType, ['ONLINE', 'CASH_ON_DELIVERY'], true)) {
            $blockers[] = 'Sposób płatności '.$paymentType.' wymaga odrębnej obsługi. W tym etapie importujemy płatności internetowe i pobrania.';
        }
        if (filled(data_get($raw, 'payment.reconciliation'))
            || collect((array) ($raw['lineItems'] ?? []))->contains(fn ($item) => filled($item['reconciliation'] ?? null))) {
            $blockers[] = 'Zamówienie zawiera dodatkowe rozliczenie Allegro Ceny, które wymaga sprawdzenia.';
        }

        $payments = [];
        $mainPayment = $raw['payment'] ?? null;
        if (is_array($mainPayment)) {
            $payments[] = $this->payment($mainPayment, $currency, $cashOnDelivery);
        }
        foreach ((array) ($raw['surcharges'] ?? []) as $surcharge) {
            if (! is_array($surcharge)) {
                throw new AllegroImportException('Nieprawidłowe dane dopłaty Allegro.');
            }
            if (($surcharge['type'] ?? null) !== 'ONLINE' || filled($surcharge['reconciliation'] ?? null)) {
                $blockers[] = 'Dopłata Allegro wymaga odrębnej obsługi rozliczenia.';
            }
            $payments[] = $this->payment($surcharge, $currency, false);
        }
        $paymentIds = array_column($payments, 'external_payment_id');
        if (count(array_unique($paymentIds)) !== count($paymentIds)) {
            throw new AllegroImportException('Allegro zwróciło powtórzony identyfikator płatności.');
        }

        $paid = '0.00';
        $paidAt = null;
        foreach ($payments as $payment) {
            if ($payment['paid_at'] !== null && $payment['amount'] !== null) {
                $paid = $this->decimal->add($paid, $payment['amount']);
                $paidAt = $paidAt === null || $payment['paid_at']->greaterThan($paidAt) ? $payment['paid_at'] : $paidAt;
            }
        }
        if ($cashOnDelivery && $this->decimal->compare($paid, '0.00') > 0) {
            $blockers[] = 'Zamówienie pobraniowe zawiera dodatkową wpłatę i wymaga sprawdzenia.';
        }
        $paymentStatus = OrderPaymentStateService::STATUS_UNPAID;
        try {
            $state = $this->paymentState->explicit($total, $paid,
                $this->decimal->compare($paid, $total) === 0 && $this->decimal->compare($total, '0.00') > 0
                    ? OrderPaymentStateService::STATUS_PAID
                    : OrderPaymentStateService::STATUS_UNPAID);
            $paymentStatus = $state['payment_status'];
        } catch (OrderPaymentStateException $exception) {
            $blockers[] = $exception->getMessage();
        }
        if (! $cashOnDelivery && $paymentStatus === OrderPaymentStateService::STATUS_UNPAID) {
            $warnings[] = 'Zamówienie nie jest w pełni opłacone. OMS zachowa rzeczywistą kwotę wpłaty.';
        }

        $email = $this->text(data_get($raw, 'buyer.email'));
        $shipping = $this->address((array) data_get($raw, 'delivery.address', []), $email);
        $invoiceRequired = data_get($raw, 'invoice.required') === true;
        $invoiceAddress = data_get($raw, 'invoice.address');
        $billingFromShipping = ! is_array($invoiceAddress) || $invoiceAddress === [];
        $billing = $billingFromShipping ? $shipping : $this->billingAddress($invoiceAddress, $email);
        if ($billingFromShipping) {
            $warnings[] = 'Allegro nie przekazało osobnego adresu do faktury. Dane rozliczeniowe zostaną skopiowane z adresu dostawy.';
            if ($invoiceRequired) {
                $blockers[] = 'Kupujący zażądał faktury, ale Allegro nie przekazało danych do faktury.';
            }
        }
        if (blank($shipping['street']) || blank($shipping['postal_code']) || blank($shipping['city']) || blank($shipping['country_code'])) {
            $blockers[] = 'Brakuje pełnego adresu dostawy z formularza Allegro.';
        }
        $phone = $shipping['phone'] ?: PhoneNumberFormatter::normalize($this->text(data_get($raw, 'buyer.phoneNumber')));
        if (blank($phone)) {
            $warnings[] = 'Allegro nie przekazało numeru telefonu kupującego.';
        }
        $point = (array) data_get($raw, 'delivery.pickupPoint', []);
        $fulfillment = $this->text(data_get($raw, 'fulfillment.status'), 50);
        if (in_array($fulfillment, ['SENT', 'READY_FOR_PICKUP', 'PICKED_UP', 'CANCELLED'], true)) {
            $warnings[] = 'Stan realizacji w Allegro: '.$fulfillment.'. W OMS zamówienie otrzyma status Nowe.';
        }

        $preview = [
            'external_order_id' => strtolower($raw['id']),
            'seller_user_id' => (string) $account->allegro_user_id,
            'environment' => $account->environment,
            'external_status' => $status,
            'fulfillment_status' => $fulfillment,
            'revision' => $this->text($raw['revision'] ?? null),
            'marketplace_id' => $this->text(data_get($raw, 'marketplace.id'), 40),
            'customer_login' => $this->text(data_get($raw, 'buyer.login')),
            'buyer_name' => $this->text(trim((string) data_get($raw, 'buyer.firstName').' '.(string) data_get($raw, 'buyer.lastName'))),
            'customer_email' => $email,
            'customer_phone' => $phone,
            'shipping_address' => $shipping,
            'billing_address' => $billing,
            'invoice_required' => $invoiceRequired,
            'billing_from_shipping' => $billingFromShipping,
            'smart' => data_get($raw, 'delivery.smart') === true,
            'delivery_method_id' => $this->text(data_get($raw, 'delivery.method.id')),
            'declared_packages' => $this->optionalQuantity(data_get($raw, 'delivery.calculatedNumberOfPackages')),
            'shipping_method' => $this->text(data_get($raw, 'delivery.method.name')),
            'pickup_point' => [
                'id' => $this->text($point['id'] ?? null),
                'name' => $this->text($point['name'] ?? null),
                'address' => $this->text(data_get($point, 'address.street')),
                'postal_code' => $this->text(data_get($point, 'address.zipCode')),
                'city' => $this->text(data_get($point, 'address.city')),
            ],
            'buyer_message' => $this->text($raw['messageToSeller'] ?? null, 10000),
            'purchased_at' => collect($items)->pluck('bought_at')->filter()->sortBy(fn ($date) => $date->getTimestamp())->first(),
            'external_updated_at' => $this->date($raw['updatedAt'] ?? null),
            'currency' => $currency,
            'total_gross' => $total,
            'delivery_cost_gross' => $deliveryCost,
            'paid_amount' => $paid,
            'payment_status' => $paymentStatus,
            'paid_at' => $this->decimal->compare($paid, '0.00') > 0 ? $paidAt : null,
            'payment_method' => $cashOnDelivery ? 'Pobranie' : (AllegroPaymentProvider::label($provider) ?: $paymentType),
            'cash_on_delivery' => $cashOnDelivery,
            'items' => $items,
            'payments' => $payments,
            'details_snapshot' => $this->information->snapshot($raw),
        ];
        $preview = $this->catalog->apply($account, $preview);
        $blockers = array_merge($blockers, $preview['catalog_blockers']);
        $warnings = array_merge($warnings, $preview['catalog_warnings']);
        if (collect($preview['items'])->contains(fn (array $item): bool => $item['vat_rate'] === null)) {
            $warnings[] = 'Stawki VAT pozycji wymagają sprawdzenia przed wystawieniem faktury.';
        }
        $preview['source_hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));
        $link = AllegroOrderLink::query()->where('environment', $account->environment)
            ->where('seller_user_id', $account->allegro_user_id)->where('external_order_id', $identifier)->first();
        $preview['existing_order_id'] = $link?->order_id;
        $preview['existing_order_trashed'] = (bool) $link?->order?->trashed();
        if ($link !== null && $link->order === null) {
            $blockers[] = 'To zamówienie było już importowane, ale jego rekord OMS został trwale usunięty.';
        } elseif ($link === null && Order::withTrashed()->where('source', 'allegro')
            ->doesntHave('allegroOrderLink')->whereRaw('LOWER(external_id) = ?', [strtolower($identifier)])->exists()) {
            $blockers[] = 'W OMS istnieje już zamówienie Allegro z tym identyfikatorem bez bezpiecznego powiązania importu.';
        }
        $preview['blockers'] = array_values(array_unique($blockers));
        $preview['warnings'] = $warnings;
        $preview['importable'] = $blockers === [] && ! $preview['existing_order_trashed'];

        return $preview;
    }

    public function provisional(AllegroAccount $account, string $identifier, array $raw, ?string $error = null): array
    {
        if (! Str::isUuid($identifier) || strtolower((string) ($raw['id'] ?? '')) !== strtolower($identifier)) {
            throw new AllegroImportException('Nieprawidlowy identyfikator zakupu Allegro.');
        }
        $currency = strtoupper((string) data_get($raw, 'summary.totalToPay.currency', data_get($raw, 'lineItems.0.price.currency', '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new AllegroImportException('Brak prawidlowej waluty zakupu.');
        }
        $items = $this->items((array) ($raw['lineItems'] ?? []), $currency);
        if ($items === []) {
            throw new AllegroImportException('Zakup nie zawiera pozycji.');
        }
        $sum = '0.00';
        foreach ($items as $item) {
            $sum = $this->decimal->add($sum, $item['total_price_gross']);
        }
        $knownDelivery = is_array(data_get($raw, 'delivery.cost'));
        $ready = ($raw['status'] ?? null) === 'READY_FOR_PROCESSING';
        $delivery = $knownDelivery ? $this->money(data_get($raw, 'delivery.cost'), $currency) : '0.00';
        $preview = [
            'external_order_id' => strtolower($identifier), 'seller_user_id' => (string) $account->allegro_user_id,
            'environment' => $account->environment, 'external_status' => (string) ($raw['status'] ?? 'BOUGHT'),
            'fulfillment_status' => $this->text(data_get($raw, 'fulfillment.status')),
            'revision' => $this->text($raw['revision'] ?? null), 'marketplace_id' => $this->text(data_get($raw, 'marketplace.id')),
            'customer_login' => $this->text(data_get($raw, 'buyer.login')), 'customer_email' => $this->text(data_get($raw, 'buyer.email')),
            'buyer_name' => $this->text(trim((string) data_get($raw, 'buyer.firstName').' '.(string) data_get($raw, 'buyer.lastName'))),
            'customer_phone' => PhoneNumberFormatter::normalize($this->text(data_get($raw, 'buyer.phoneNumber'))),
            // Profile addresses are not delivery instructions before the completed checkout form.
            'shipping_address' => $this->address($ready ? (array) data_get($raw, 'delivery.address', []) : [], null),
            'billing_address' => $ready && is_array(data_get($raw, 'invoice.address')) ? $this->billingAddress(data_get($raw, 'invoice.address'), null) : $this->address([], null),
            'invoice_required' => $ready && data_get($raw, 'invoice.required') === true, 'smart' => $ready && data_get($raw, 'delivery.smart') === true, 'delivery_method_id' => $this->text(data_get($raw, 'delivery.method.id')),
            'declared_packages' => null, 'shipping_method' => $this->text(data_get($raw, 'delivery.method.name')),
            'pickup_point' => array_fill_keys(['id', 'name', 'address', 'postal_code', 'city'], null),
            'buyer_message' => $this->text($raw['messageToSeller'] ?? null, 10000),
            'purchased_at' => collect($items)->pluck('bought_at')->filter()->sortBy(fn ($date) => $date->getTimestamp())->first(),
            'external_updated_at' => $this->date($raw['updatedAt'] ?? null), 'currency' => $currency,
            'total_gross' => is_array(data_get($raw, 'summary.totalToPay')) ? $this->money(data_get($raw, 'summary.totalToPay'), $currency) : $this->decimal->add($sum, $delivery), 'delivery_cost_gross' => $delivery,
            'paid_amount' => '0.00', 'payment_status' => OrderPaymentStateService::STATUS_UNPAID, 'paid_at' => null,
            'payment_method' => AllegroPaymentProvider::label($this->text(data_get($raw, 'payment.provider'))),
            'cash_on_delivery' => data_get($raw, 'payment.type') === 'CASH_ON_DELIVERY',
            'items' => $items, 'payments' => [], 'details_snapshot' => $this->information->snapshot($raw), 'delivery_cost_known' => $knownDelivery,
            'processing_error' => $error,
        ];
        $preview = $this->catalog->apply($account, $preview);
        if (! $ready) {
            $preview['details_snapshot']['shipping_address'] = '';
        }
        if ($preview['catalog_blockers'] !== []) {
            $preview['processing_error'] = trim(($error ?? '').' '.implode(' ', $preview['catalog_blockers']));
            $preview['catalog_unavailable'] = true;
            $fallbackAccount = clone $account;
            $fallbackAccount->use_linked_products = false;
            $preview = $this->catalog->apply($fallbackAccount, $preview);
        }
        $preview['source_hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));
        $preview['importable'] = false;

        return $preview;
    }

    private function items(array $lines, string $currency): array
    {
        $items = [];
        $ids = [];
        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! Str::isUuid($line['id'])) {
                throw new AllegroImportException('Allegro zwróciło nieprawidłowe dane pozycji zamówienia.');
            }
            $id = strtolower($line['id']);
            if (in_array($id, $ids, true)) {
                throw new AllegroImportException('Allegro zwróciło powtórzoną pozycję zamówienia.');
            }
            $ids[] = $id;
            $quantity = $this->quantity($line['quantity'] ?? null);
            $price = $this->money($line['price'] ?? null, $currency);
            if (blank(data_get($line, 'offer.id'))) {
                throw new AllegroImportException('Brakuje identyfikatora oferty Allegro.');
            }
            $items[] = $this->item($id, $this->text(data_get($line, 'offer.name')),
                $this->text(data_get($line, 'offer.id')), $quantity, $price, $currency, $line['boughtAt'] ?? null, $line['tax'] ?? null);

            foreach ((array) ($line['selectedAdditionalServices'] ?? []) as $index => $service) {
                if (! is_array($service)) {
                    throw new AllegroImportException('Nieprawidłowe dane usługi dodatkowej Allegro.');
                }
                $items[] = $this->item($id.':service:'.$index, $this->text($service['name'] ?? null), null,
                    $this->quantity($service['quantity'] ?? null), $this->money($service['price'] ?? null, $currency),
                    $currency, $line['boughtAt'] ?? null, $service['tax'] ?? null);
            }
        }

        return $items;
    }

    private function item(string $id, ?string $name, ?string $offerId, int $quantity, string $price, string $currency, mixed $boughtAt, mixed $tax): array
    {
        if (blank($name) || ($offerId !== null && preg_match('/^[0-9]+$/', $offerId) !== 1)) {
            throw new AllegroImportException('Brakuje nazwy produktu lub numer oferty Allegro jest nieprawidłowy.');
        }

        return [
            'external_id' => $id,
            'product_name' => $name,
            'offer_id' => $offerId,
            'quantity' => $quantity,
            'unit_price_gross' => $price,
            'total_price_gross' => $this->money(['amount' => $this->decimal->multiply($price, $quantity), 'currency' => $currency], $currency),
            'currency' => $currency,
            'bought_at' => $this->date($boughtAt),
            'source_vat' => $tax !== null && ! is_array($tax)
                ? ['status' => 'invalid', 'rate' => null]
                : $this->vat->source($tax['rate'] ?? null, ! empty($tax['exemption'])),
        ];
    }

    private function payment(array $payment, string $currency, bool $cod): array
    {
        $id = $this->text($payment['id'] ?? null);
        if ($id === null) {
            throw new AllegroImportException('Brakuje identyfikatora płatności Allegro.');
        }
        $paidAmount = $payment['paidAmount'] ?? null;
        $paidAt = $cod ? null : $this->date($payment['finishedAt'] ?? null);
        if ($cod && is_array($paidAmount) && $this->decimal->compare($this->money($paidAmount, $currency), '0.00') > 0) {
            throw new AllegroImportException('Płatność pobraniowa zawiera nietypową wpłatę.');
        }
        if (is_array($paidAmount) && $paidAt === null && ! $cod) {
            throw new AllegroImportException('Allegro zwróciło kwotę wpłaty bez daty zakończenia płatności.');
        }
        if ($paidAt !== null && ! is_array($paidAmount)) {
            throw new AllegroImportException('Allegro zwróciło zakończoną płatność bez kwoty wpłaty.');
        }

        return [
            'external_payment_id' => $id,
            'type' => $this->text($payment['type'] ?? null, 40) ?? 'UNKNOWN',
            'provider' => $this->text($payment['provider'] ?? null, 60),
            'amount' => is_array($paidAmount) && ! $cod ? $this->money($paidAmount, $currency) : null,
            'currency' => $currency,
            'paid_at' => $paidAt,
        ];
    }

    private function address(array $address, ?string $email): array
    {
        return [
            'name' => $this->text(trim(implode(' ', array_filter([$this->text($address['firstName'] ?? null), $this->text($address['lastName'] ?? null)])))),
            'company_name' => $this->text($address['companyName'] ?? null),
            'tax_id' => null,
            'street' => $this->text($address['street'] ?? null),
            'building_number' => null,
            'apartment_number' => null,
            'postal_code' => $this->text($address['zipCode'] ?? null),
            'city' => $this->text($address['city'] ?? null),
            'province' => null,
            'country_code' => strtoupper($this->text($address['countryCode'] ?? null, 2) ?? '') ?: null,
            'phone' => PhoneNumberFormatter::normalize($this->text($address['phoneNumber'] ?? null)),
            'email' => $email,
        ];
    }

    private function billingAddress(array $address, ?string $email): array
    {
        $company = (array) ($address['company'] ?? []);
        $person = (array) ($address['naturalPerson'] ?? []);
        $result = $this->address(array_merge($address, $person), $email);
        $result['company_name'] = $this->text($company['name'] ?? null);
        $result['tax_id'] = $this->text($company['taxId'] ?? null);

        return $result;
    }

    private function money(mixed $value, string $currency): string
    {
        if (! is_array($value) || ($value['currency'] ?? null) !== $currency) {
            throw new AllegroImportException('Brakuje kwoty Allegro lub waluta kwoty jest niezgodna z walutą zamówienia.');
        }
        try {
            return $this->financial->assertOrderMoney($value['amount'] ?? null, 'Kwota Allegro wykracza poza obsługiwany zakres lub ma nieprawidłową precyzję.');
        } catch (InvoiceDomainException $exception) {
            throw new AllegroImportException($exception->getMessage());
        }
    }

    private function text(mixed $value, int $max = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > $max) {
            throw new AllegroImportException('Allegro zwróciło nieprawidłową lub zbyt długą wartość tekstową.');
        }

        return trim($value) ?: null;
    }

    private function quantity(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > 2147483647) {
            throw new AllegroImportException('Allegro zwróciło nieprawidłową ilość produktu lub usługi.');
        }

        return $value;
    }

    private function optionalQuantity(mixed $value): ?int
    {
        return $value === null ? null : $this->quantity($value);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
                throw new AllegroImportException('Nieprawidłowa data Allegro.');
            }
            $parsed = date_parse($value);
            if ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0) {
                throw new AllegroImportException('Nieprawidłowa data Allegro.');
            }

            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new AllegroImportException('Allegro zwróciło nieprawidłową datę.');
        }
    }
}
