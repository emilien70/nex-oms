<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Support\AddressLineFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Invoices\Services\InvoiceMoneyFormatter;

class VonHalskyOrderPreviewService
{
    public function __construct(
        private readonly VonHalskyClient $client,
        private readonly VonHalskyAccountService $accounts,
        private readonly InvoiceMoneyFormatter $moneyFormatter,
        private readonly VonHalskyOrderNormalizer $normalizer,
    ) {}

    public function preview(VonHalskyAccount $account, string $id, array $quantities = []): array
    {
        $data = $this->client->order($account, $id);
        $rules = [
            'id' => ['required', 'string', 'max:255'],
            'organizationId' => ['required', 'uuid'],
            'createdAt' => ['required', 'date'],
            'updatedAt' => ['nullable', 'date'],
            'status' => ['required', 'string', 'max:50'],
            'customer' => ['nullable', 'array'],
            'invoice' => ['nullable', 'array'],
            'delivery' => ['required', 'array'],
            'delivery.deliveryType' => ['required', 'string', 'max:50'],
            'delivery.deliveryPoint' => ['nullable', 'string', 'max:255'],
            'delivery.expectedDeliveryDate' => ['nullable', 'date'],
            'delivery.courierNote' => ['nullable', 'string', 'max:10000'],
            'orderLines' => ['required', 'array', 'min:1', 'max:1000'],
            'orderLines.*' => ['required', 'array'],
            'orderLines.*.offer' => ['required', 'array'],
            'orderLines.*.offer.product' => ['required', 'array'],
            'orderLines.*.offer.product.name' => ['required', 'string', 'max:1000'],
            'paymentDetails' => ['required', 'array'],
            'paymentDetails.selectedPaymentType' => ['nullable', 'string', 'max:50'],
            'paymentDetails.payments' => ['present', 'array', 'max:100'],
            'paymentDetails.payments.*' => ['required', 'array'],
            'paymentDetails.payments.*.paymentId' => ['nullable', 'string', 'max:255'],
            'paymentDetails.payments.*.paymentType' => ['nullable', 'string', 'max:50'],
            'paymentDetails.payments.*.paymentDate' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:20000'],
        ];

        foreach (['customer', 'invoice', 'delivery'] as $prefix) {
            foreach (['firstName', 'lastName', 'companyName', 'email', 'phoneNumber', 'name', 'taxIdPrefix', 'taxId'] as $field) {
                $rules[$prefix.'.'.$field] = ['nullable', 'string', 'max:500'];
            }
            $rules[$prefix.'.address'] = ['nullable', 'array'];
            foreach (['street', 'building', 'flat', 'postCode', 'city', 'state', 'countryCode'] as $field) {
                $rules[$prefix.'.address.'.$field] = ['nullable', 'string', 'max:500'];
            }
        }

        if (Validator::make($data, $rules)->fails()
            || $data['id'] !== $id
            || strtolower($data['organizationId']) !== strtolower($account->organization_id)) {
            throw new VonHalskyApiException('Dane zamówienia Von Halsky są nieprawidłowe lub nie należą do wybranej organizacji.');
        }

        $lines = [];
        foreach ($data['orderLines'] as $line) {
            $offer = $line['offer'];
            $lines[] = [
                'name' => $offer['product']['name'],
                'price' => $this->money($offer['finalPrice'] ?? $offer['price'] ?? null),
            ];
        }

        $customer = $data['customer'] ?? [];
        $invoice = $data['invoice'] ?? [];
        $delivery = $data['delivery'];
        $payments = $data['paymentDetails']['payments'];
        $result = [
            'id' => $data['id'],
            'created_at' => $this->date($data['createdAt']),
            'updated_at' => $this->date($data['updatedAt'] ?? null),
            'status' => $data['status'],
            'status_label' => match ($data['status']) {
                'CREATED' => 'Utworzone', 'ACCEPTED' => 'Zaakceptowane', 'REFUSED' => 'Odrzucone przez sprzedawcę',
                'REJECTED' => 'Odrzucone przez platformę', 'CANCELED' => 'Anulowane', default => 'Nieznany status',
            },
            'customer' => $this->contact($customer),
            'customer_available' => filled($customer['email'] ?? null) || filled($customer['firstName'] ?? null) || filled($customer['lastName'] ?? null),
            'invoice' => $invoice === [] ? null : $this->contact($invoice) + [
                'tax_id' => trim(($invoice['taxIdPrefix'] ?? '').' '.($invoice['taxId'] ?? '')),
            ],
            'delivery' => $this->contact($delivery) + [
                'type' => $delivery['deliveryType'],
                'point' => $delivery['deliveryPoint'] ?? null,
                'price' => $this->money($delivery['price'] ?? null),
                'expected_at' => $this->date($delivery['expectedDeliveryDate'] ?? null),
                'note' => $delivery['courierNote'] ?? null,
            ],
            'lines' => $lines,
            'total' => $this->money($data['finalPrice'] ?? null),
            'base_price' => $this->money($data['basePrice'] ?? null),
            'payment_type' => $data['paymentDetails']['selectedPaymentType'] ?? null,
            'payment_recorded' => count($payments) > 0,
            'payments' => array_map(fn (array $payment): array => [
                'id' => $payment['paymentId'] ?? null,
                'type' => $payment['paymentType'] ?? null,
                'date' => $this->date($payment['paymentDate'] ?? null),
            ], $payments),
            'comment' => $data['comment'] ?? null,
        ];
        $current = $this->accounts->current($account);
        if (! $current->is_active) {
            throw new VonHalskyApiException('Połączenie zostało wyłączone. Odśwież stronę.');
        }

        try {
            $result['import'] = $this->normalizer->normalize($account, $data, $quantities);
        } catch (VonHalskyApiException $exception) {
            $result['import'] = ['blockers' => [$exception->getMessage()], 'importable' => false,
                'requires_quantities' => false, 'items' => [], 'source_hash' => null];
        }

        return $result;
    }

    private function contact(array $data): array
    {
        $address = $data['address'] ?? [];

        return [
            'name' => trim(($data['firstName'] ?? '').' '.($data['lastName'] ?? '')) ?: ($data['name'] ?? null),
            'company' => $data['companyName'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phoneNumber'] ?? null,
            'address' => array_values(array_filter([
                AddressLineFormatter::formatAddressLine($address['street'] ?? null, $address['building'] ?? null, $address['flat'] ?? null),
                AddressLineFormatter::formatPostalCity($address['postCode'] ?? null, $address['city'] ?? null),
                $address['countryCode'] ?? null,
            ], fn ($value): bool => filled($value))),
        ];
    }

    private function money(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $amount = is_array($value) ? ($value['amount'] ?? null) : null;
        $currency = is_array($value) ? ($value['currency'] ?? null) : null;
        if ((! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || ! is_string($currency) || ! preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową kwotę lub walutę.');
        }

        // API returns JSON numbers. This is display-only, never a payment or import calculation.
        try {
            $decimal = BigDecimal::of((string) $amount)->toScale(2);
            if ($decimal->isNegative() || $decimal->isGreaterThan('999999999.99')) {
                throw new VonHalskyApiException('Kwota Von Halsky jest poza zakresem podglądu.');
            }
        } catch (MathException) {
            throw new VonHalskyApiException('Von Halsky zwrócił nieprawidłową dokładność kwoty.');
        }

        return $this->moneyFormatter->format((string) $decimal).' '.$currency;
    }

    private function date(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->setTimezone(config('app.timezone'))->format('d.m.Y H:i:s');
    }
}
