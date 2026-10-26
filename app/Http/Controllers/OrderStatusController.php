<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use App\Support\AddressLineFormatter;
use App\Support\CountryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Automation\Services\OrderAutomationHistory;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\Prestashop\Services\PrestashopPaymentStatusService;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;

class OrderStatusController extends Controller
{
    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly OrderSalesDocumentActionsView $salesDocumentActions,
        private readonly OrderAutomationHistory $automationHistory,
    ) {}

    public function state(Request $request, Order $order): JsonResponse
    {
        return $this->stateResponse($order, $request);
    }

    public function update(Request $request, Order $order, OrderStatusService $orderStatusService): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(OrderStatusSetting::orderedStatuses()))],
        ]);

        $orderStatusService->change($order, $validated['status'], 'manual');

        if ($request->expectsJson()) {
            return $this->stateResponse($order->refresh());
        }

        return back();
    }

    private function stateResponse(Order $order, ?Request $request = null): JsonResponse
    {
        $order->load([
            'items.product',
            'items.prestashopItemLink.orderLink.account',
            'prestashopOrderLinks.account',
            'prestashopBuyerMessages',
            'prestashopOrderLinks.payments',
            'allegroOrderLink.account',
            'allegroOrderLink.payments',
            'shipments' => fn ($query) => $query
                ->whereNotNull('tracking_number')
                ->orderByDesc('created_at'),
            'shipments.courierAccount',
            'events' => fn ($query) => $query->orderByDesc('created_at'),
            'payNowPayments' => fn ($query) => $query
                ->where('status', PayNowPaymentStatus::Confirmed->value)
                ->orderByDesc('confirmed_at')
                ->orderByDesc('id'),
        ]);

        $statusChangedAt = $order->status_changed_at ?? $order->created_at;
        $latestEventId = (int) ($order->events->max('id') ?? 0);
        $shipmentsSignature = $this->shipmentsSignature($order->shipments);
        $requestedFragments = collect(explode(',', (string) $request?->query('fragments', '')))
            ->filter()
            ->unique();
        $paymentIdWarning = app(PrestashopPaymentStatusService::class)->hasMissingTransactionId($order);
        $buyerCommentsHtml = view('orders.partials.buyer-comments', compact('order'))->render();
        $buyerCommentsSignature = hash('sha256', $buyerCommentsHtml);
        if ($request?->query('buyer_comments_signature') !== $buyerCommentsSignature) {
            $requestedFragments->push('buyer-comments');
        }
        if ($request?->has('payment_id_warning') && $request->boolean('payment_id_warning') !== $paymentIdWarning) {
            $requestedFragments->push('order-info');
            $requestedFragments->push('payments');
        }

        if ($request?->has('latest_event_id') && (int) $request->query('latest_event_id') !== $latestEventId) {
            $requestedFragments->push('history');
            $requestedFragments->push('automation-history');
            $requestedFragments->push('payments');
        }

        if ($request?->has('shipments_signature') && (string) $request->query('shipments_signature') !== $shipmentsSignature) {
            $requestedFragments->push('shipments');
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'status_color' => OrderStatusSetting::colorFor($order->status),
            'status_text_color' => OrderStatusSetting::textColorFor($order->status),
            'status_changed_at' => $statusChangedAt ? [
                'date' => $statusChangedAt->format('Y-m-d'),
                'time' => $statusChangedAt->format('H:i'),
                'iso' => $statusChangedAt->toIso8601String(),
            ] : null,
            'updated_at' => $order->updated_at?->toIso8601String(),
            'latest_event_id' => $latestEventId,
            'payment_id_warning' => $paymentIdWarning,
            'buyer_comments_signature' => $buyerCommentsSignature,
            'shipments_signature' => $shipmentsSignature,
            'shipments_count' => $order->shipments->count(),
            'header_customer' => $order->shipping_name ?: $order->customer_login,
            'star_color' => $order->star_color,
            'fields' => $this->editableFields($order),
            'fragments' => $this->renderFragments($order, $requestedFragments->unique(), $request?->integer('automation_page', 1) ?? 1)
                + ($requestedFragments->contains('buyer-comments') ? ['buyer-comments' => $buyerCommentsHtml] : []),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function renderFragments(Order $order, Collection $requested, int $automationPage): array
    {
        $fragments = [];

        if ($requested->contains('order-info')) {
            $fragments['order-info'] = view('orders.partials.order-info-view', compact('order'))->render();
        }

        if ($requested->contains('shipping')) {
            $fragments['shipping'] = view('orders.partials.address', [
                'address' => $order->shippingAddressData(),
                'showTaxId' => false,
                'showCountry' => true,
                'countryName' => $this->countries->name($order->shipping_country_code),
                'showProvince' => false,
                'showPhone' => false,
                'showEmail' => false,
                'editFields' => [
                    'Imię i Nazwisko' => 'shipping_name',
                    'Firma' => 'shipping_company_name',
                    'Adres' => 'shipping_address_line',
                    'Kod i miasto' => 'shipping_postal_code',
                    'Miasto' => 'shipping_city',
                    'Kraj' => 'shipping_country_code',
                ],
            ])->render();
        }

        if ($requested->contains('billing')) {
            $fragments['billing'] = view('orders.partials.address', [
                'address' => $order->billingAddressData(),
                'showTaxId' => true,
                'showCountry' => true,
                'countryName' => $this->countries->name($order->billing_country_code),
                'showProvince' => false,
                'showPhone' => false,
                'showEmail' => false,
                'taxIdLast' => true,
                'editFields' => [
                    'Imię i Nazwisko' => 'billing_name',
                    'Firma' => 'billing_company_name',
                    'Adres' => 'billing_address_line',
                    'Kod i miasto' => 'billing_postal_code',
                    'Miasto' => 'billing_city',
                    'Kraj' => 'billing_country_code',
                    'NIP' => 'billing_tax_id',
                ],
            ])->render();
        }

        if ($requested->contains('pickup')) {
            $fragments['pickup'] = view('orders.partials.pickup-view', compact('order'))->render();
        }

        if ($requested->contains('products')) {
            $fragments['products'] = view('orders.partials.product-rows', compact('order'))->render();
        }

        if ($requested->contains('shipments')) {
            $fragments['shipments'] = $order->shipments
                ->map(fn ($shipment): string => view('orders.partials.shipment-row', compact('shipment'))->render())
                ->implode('');
        }

        if ($requested->contains('history')) {
            $fragments['history'] = view('orders.partials.history', compact('order'))->render();
        }

        if ($requested->contains('automation-history')) {
            $fragments['automation-history'] = view('orders.partials.automation-history', [
                'automationHistory' => $this->automationHistory->forOrder($order, $automationPage),
            ])->render();
        }

        if ($requested->contains('payments')) {
            $fragments['payments'] = view('orders.partials.payments', compact('order'))->render();
        }

        if ($requested->contains('sales-documents')) {
            $fragments['sales-documents'] = $this->salesDocumentActions->render($order);
        }

        return $fragments;
    }

    private function editableFields(Order $order): array
    {
        return [
            'customer_login' => $order->customer_login,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'source' => $order->source,
            'shipping_method' => $order->shipping_method,
            'cash_on_delivery' => $order->cash_on_delivery ? '1' : '0',
            'delivery_cost_gross' => $order->delivery_cost_gross,
            'payment_method' => $order->payment_method,
            'notes' => $order->notes,
            'paid_amount' => $order->paid_amount,
            'currency' => $order->currency,
            'shipping_name' => $order->shipping_name,
            'shipping_company_name' => $order->shipping_company_name,
            'shipping_address_line' => AddressLineFormatter::formatAddressLine($order->shipping_street, $order->shipping_building_number, $order->shipping_apartment_number),
            'shipping_postal_code' => $order->shipping_postal_code,
            'shipping_city' => $order->shipping_city,
            'billing_name' => $order->billing_name,
            'billing_company_name' => $order->billing_company_name,
            'billing_address_line' => AddressLineFormatter::formatAddressLine($order->billing_street, $order->billing_building_number, $order->billing_apartment_number),
            'billing_postal_code' => $order->billing_postal_code,
            'billing_city' => $order->billing_city,
            'billing_tax_id' => $order->billing_tax_id,
            'pickup_point_name' => $order->pickup_point_name,
            'pickup_point_id' => $order->pickup_point_id,
            'pickup_point_address' => $order->pickup_point_address,
            'pickup_point_postal_code' => $order->pickup_point_postal_code,
            'pickup_point_city' => $order->pickup_point_city,
        ];
    }

    private function shipmentsSignature(Collection $shipments): string
    {
        return sha1($shipments->map(fn ($shipment): array => [
            $shipment->id,
            $shipment->status,
            $shipment->oms_status,
            $shipment->tracking_number,
            $shipment->error_message,
            $shipment->updated_at?->toIso8601String(),
        ])->values()->toJson());
    }
}
