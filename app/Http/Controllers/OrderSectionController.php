<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderCurrencyException;
use App\Http\Controllers\Concerns\NormalizesDecimalInput;
use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Rules\ValidCurrencyCode;
use App\Services\OrderCurrencyService;
use App\Services\OrderTotalService;
use App\Support\AddressLineFormatter;
use App\Support\CountryCatalog;
use App\Support\CurrencyCatalog;
use App\Support\PhoneNumberFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderSectionController extends Controller
{
    use NormalizesDecimalInput;
    use RespondsToOrderAjax;

    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly CurrencyCatalog $currencies,
    ) {}

    public function updateOrderInfo(Request $request, Order $order, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        $this->normalizeDecimalFields($request, ['delivery_cost_gross']);

        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'customer_login' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'cash_on_delivery' => ['nullable', 'boolean'],
            'delivery_cost_gross' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($order, $validated, $orderTotalService): void {
                $order->update([
                    'source' => $validated['source'],
                    'notes' => $validated['notes'] ?? null,
                    'customer_login' => $validated['customer_login'] ?? null,
                    'customer_email' => $validated['customer_email'] ?? null,
                    'customer_phone' => PhoneNumberFormatter::normalize($validated['customer_phone'] ?? null),
                    'shipping_method' => $validated['shipping_method'] ?? null,
                    'cash_on_delivery' => (bool) ($validated['cash_on_delivery'] ?? false),
                    'delivery_cost_gross' => $validated['delivery_cost_gross'] ?? 0,
                    'payment_method' => $validated['payment_method'] ?? null,
                ]);

                if ($order->wasChanged('delivery_cost_gross')) {
                    $orderTotalService->recalculate($order);
                }

                $this->addEvent(
                    $order,
                    'order_info_updated',
                    html_entity_decode('Informacje o zam&oacute;wieniu zaktualizowane', ENT_QUOTES, 'UTF-8'),
                    html_entity_decode('Zaktualizowano informacje o zam&oacute;wieniu', ENT_QUOTES, 'UTF-8')
                );
            });
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse($request, ['order-info', 'history'], back());
    }

    public function updateShippingAddress(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $this->validateAddress($request, 'shipping');

        DB::transaction(function () use ($order, $validated): void {
            $order->update($this->addressData($validated, 'shipping'));

            $this->addEvent(
                $order,
                'shipping_address_updated',
                'Adres dostawy zaktualizowany',
                'Zaktualizowano adres dostawy'
            );
        });

        return $this->orderMutationResponse($request, ['shipping', 'history'], back());
    }

    public function updateBillingAddress(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $this->validateAddress($request, 'billing', [
            'billing_tax_id' => ['nullable', 'string', 'max:32'],
        ]);

        DB::transaction(function () use ($order, $validated): void {
            $order->update($this->addressData($validated, 'billing'));

            $this->addEvent(
                $order,
                'billing_address_updated',
                'Dane do faktury zaktualizowane',
                'Zaktualizowano dane do faktury'
            );
        });

        return $this->orderMutationResponse($request, ['billing', 'history'], back());
    }

    public function updatePayment(
        Request $request,
        Order $order,
        OrderCurrencyService $orderCurrencyService,
    ): RedirectResponse {
        $this->normalizeDecimalFields($request, ['total_gross', 'delivery_cost_gross']);
        $request->merge([
            'currency' => $this->currencies->normalize(
                $request->exists('currency') ? $request->input('currency') : $order->currency,
            ),
        ]);

        $validated = $request->validate([
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                new ValidCurrencyCode($this->currencies, $order->currency),
            ],
            'total_gross' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost_gross' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['required', 'string', 'in:unpaid,paid,refunded'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ], [
            'currency.required' => 'Zamówienie nie ma ustalonej waluty.',
        ]);

        try {
            DB::transaction(function () use ($order, $validated, $orderCurrencyService): void {
                $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $currency = $orderCurrencyService->currencyForPaymentUpdate($managedOrder, $validated['currency']);

                $managedOrder->update([
                    'currency' => $currency,
                    'total_gross' => $validated['total_gross'] ?? 0,
                    'delivery_cost_gross' => $validated['delivery_cost_gross'] ?? 0,
                    'payment_status' => $validated['payment_status'],
                    'payment_method' => $validated['payment_method'] ?? null,
                    'paid_at' => $validated['paid_at'] ?? null,
                ]);
            });
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        $this->addEvent(
            $order,
            'payment_updated',
            html_entity_decode('P&#322;atno&#347;&#263; zaktualizowana', ENT_QUOTES, 'UTF-8'),
            html_entity_decode('Zaktualizowano dane p&#322;atno&#347;ci', ENT_QUOTES, 'UTF-8')
        );

        return back()->with('success', html_entity_decode('Dane p&#322;atno&#347;ci zosta&#322;y zapisane.', ENT_QUOTES, 'UTF-8'));
    }

    public function updateProducts(Request $request, Order $order, OrderTotalService $orderTotalService): RedirectResponse
    {
        $this->normalizeNestedDecimalFields($request, 'items', ['unit_price_gross']);

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price_gross' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($order, $validated, $orderTotalService): void {
            $order->items()->delete();

            foreach (array_slice($validated['items'] ?? [], 0, 5) as $item) {
                if (empty($item['product_name'])) {
                    continue;
                }

                $quantity = (int) ($item['quantity'] ?? 1);
                $unitPriceGross = (string) ($item['unit_price_gross'] ?? '0');

                $order->items()->create([
                    'product_name' => $item['product_name'],
                    'sku' => null,
                    'ean' => null,
                    'quantity' => $quantity,
                    'unit_price_gross' => $unitPriceGross,
                    'total_price_gross' => $orderTotalService->lineTotal($unitPriceGross, $quantity),
                    'currency' => $order->currency,
                ]);
            }

            $orderTotalService->recalculate($order);

            $this->addEvent(
                $order,
                'products_updated',
                'Produkty zaktualizowane',
                html_entity_decode('Zaktualizowano produkty w zam&oacute;wieniu', ENT_QUOTES, 'UTF-8')
            );
        });

        return back()->with('success', 'Produkty zostaly zapisane.');
    }

    private function validateAddress(Request $request, string $prefix, array $additionalRules = []): array
    {
        $countryField = $prefix.'_country_code';
        $countryCode = $request->input($countryField);

        if (is_string($countryCode)) {
            $request->merge([$countryField => $this->countries->normalize($countryCode)]);
        }

        return $request->validate(
            $this->addressRules($prefix) + $additionalRules,
            [
                $countryField.'.required' => 'Wybierz prawidłowy kraj.',
                $countryField.'.string' => 'Wybierz prawidłowy kraj.',
                $countryField.'.size' => 'Wybierz prawidłowy kraj.',
                $countryField.'.in' => 'Wybierz prawidłowy kraj.',
            ],
        );
    }

    private function addressRules(string $prefix): array
    {
        return [
            $prefix.'_name' => ['nullable', 'string', 'max:255'],
            $prefix.'_company_name' => ['nullable', 'string', 'max:255'],
            $prefix.'_address_line' => ['nullable', 'string', 'max:255'],
            $prefix.'_postal_city' => ['nullable', 'string', 'max:255'],
            $prefix.'_street' => ['nullable', 'string', 'max:255'],
            $prefix.'_building_number' => ['nullable', 'string', 'max:255'],
            $prefix.'_apartment_number' => ['nullable', 'string', 'max:255'],
            $prefix.'_postal_code' => ['nullable', 'string', 'max:255'],
            $prefix.'_city' => ['nullable', 'string', 'max:255'],
            $prefix.'_province' => ['nullable', 'string', 'max:255'],
            $prefix.'_country_code' => ['required', 'string', 'size:2', Rule::in($this->countries->codes())],
            $prefix.'_phone' => ['nullable', 'string', 'max:255'],
            $prefix.'_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    private function addressData(array $validated, string $prefix): array
    {
        $addressParts = array_key_exists($prefix.'_address_line', $validated)
            ? AddressLineFormatter::parseAddressLine($validated[$prefix.'_address_line'])
            : [
                'street' => $validated[$prefix.'_street'] ?? null,
                'building_number' => $validated[$prefix.'_building_number'] ?? null,
                'apartment_number' => $validated[$prefix.'_apartment_number'] ?? null,
            ];

        $postalCityParts = array_key_exists($prefix.'_postal_city', $validated)
            ? AddressLineFormatter::parsePostalCity($validated[$prefix.'_postal_city'])
            : [
                'postal_code' => $validated[$prefix.'_postal_code'] ?? null,
                'city' => $validated[$prefix.'_city'] ?? null,
            ];

        $data = [
            $prefix.'_name' => $validated[$prefix.'_name'] ?? null,
            $prefix.'_company_name' => $validated[$prefix.'_company_name'] ?? null,
            $prefix.'_street' => $addressParts['street'],
            $prefix.'_building_number' => $addressParts['building_number'],
            $prefix.'_apartment_number' => $addressParts['apartment_number'],
            $prefix.'_postal_code' => $postalCityParts['postal_code'],
            $prefix.'_city' => $postalCityParts['city'],
            $prefix.'_province' => $validated[$prefix.'_province'] ?? null,
            $prefix.'_country_code' => $validated[$prefix.'_country_code'],
        ];

        if ($prefix === 'billing') {
            $data['billing_tax_id'] = $validated['billing_tax_id'] ?? null;
        }

        if (array_key_exists($prefix.'_phone', $validated)) {
            $data[$prefix.'_phone'] = PhoneNumberFormatter::normalize($validated[$prefix.'_phone']);
        }

        if (array_key_exists($prefix.'_email', $validated)) {
            $data[$prefix.'_email'] = $validated[$prefix.'_email'];
        }

        return $data;
    }

    private function addEvent(Order $order, string $type, string $title, string $description): void
    {
        $order->events()->create([
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'payload' => null,
        ]);
    }
}
