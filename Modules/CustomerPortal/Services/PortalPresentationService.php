<?php

namespace Modules\CustomerPortal\Services;

use App\Models\Order;
use App\Support\CountryCatalog;
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Models\PortalStatus;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PortalPresentationService
{
    public function __construct(private readonly PortalDocumentService $documents, private readonly InvoiceDecimalCalculator $decimal, private readonly PortalPaymentService $payments, private readonly PortalUrlService $urls, private readonly CountryCatalog $countries) {}

    public function data(Order $order, PortalSetting $settings, ?string $token = null, bool $preview = false, bool $verified = false): array
    {
        $status = PortalStatus::query()->where('is_visible', true)
            ->whereHas('statusSetting', fn ($query) => $query->where('status', $order->status))->first();
        $base = $preview ? 'customer-portal.preview' : 'customer-portal';
        $context = $preview ? ['order' => $order->id] : ['orderNumber' => $order->id, 'token' => $token];
        $url = fn (string $name, array $extra = []): string => $preview ? route($name, $context + $extra) : $this->urls->route($name, $context + $extra);
        $masked = ! $preview && ! $verified && $settings->masksPersonalData();
        $remaining = $this->decimal->max($this->decimal->subtract($order->total_gross, $order->paid_amount), '0.00');

        return [
            'preview' => $preview, 'storeName' => $settings->store_name,
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/D', $settings->accent_color) ? $settings->accent_color : '#0075c9',
            'logoUrl' => $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? $url($base.'.logo') : null,
            'contactEmail' => $settings->contact_email, 'contactPhone' => $settings->contact_phone,
            'message' => $settings->customer_message, 'number' => (string) $order->id,
            'date' => ($order->purchased_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'stage' => $status?->public_name ?: 'Zamówienie zarejestrowane',
            'stageMessage' => $status?->public_message ?: 'Aktualne informacje o realizacji pojawią się w tym miejscu.',
            'currency' => $order->currency, 'total' => $order->total_gross, 'paid' => $order->paid_amount,
            'remaining' => $remaining, 'delivery' => $order->delivery_cost_gross,
            'paynow' => $this->payments->presentation($order, $settings, $token, $preview),
            'paymentMethod' => $order->payment_method ?: 'Nie podano',
            'paymentLabel' => $order->cash_on_delivery ? 'Za pobraniem' : ($this->decimal->compare($remaining, '0') <= 0 ? 'Opłacone' : 'Oczekuje na wpłatę'),
            'address' => $settings->show_address ? $this->deliveryAddress($order, $masked) : null,
            'buyer' => $settings->show_address ? $this->personalFields($order->billingAddressData(), $masked) : null,
            'masked' => $masked, 'verified' => $verified, 'challenge' => null,
            'detailsUrl' => ! $preview && $settings->show_address && $masked ? $url('customer-portal.details') : null,
            'shippingMethod' => $order->shipping_method,
            'items' => $order->items()->with('product')->orderBy('id')->get()->map(fn ($item): array => [
                'name' => $item->product_name, 'quantity' => $item->quantity, 'price' => $item->unit_price_gross,
                'total' => $item->total_price_gross,
                'image' => $settings->show_images && ($path = $item->product?->thumbnail_path ?: $item->product?->image_path)
                    && Storage::disk('local')->exists($path) ? $url($base.'.image', ['item' => $item->id]) : null,
            ])->all(),
            'shipments' => $order->shipments()->whereNotNull('tracking_number')
                ->whereNotIn('status', ['cancelled', 'cancelled_locally'])->orderBy('id')->get()
                ->map(fn ($shipment): array => ['number' => $shipment->tracking_number, 'status' => $shipment->statusLabel(),
                    'carrier' => match ($shipment->provider) {
                        'inpost_lockers', 'inpost_courier' => 'InPost', 'dpd' => 'DPD', 'allegro_shipping' => 'Allegro Delivery', default => 'Przesyłka'
                    },
                    'tracking' => $shipment->trackingUrl(), 'date' => $shipment->oms_status_changed_at?->format('d.m.Y H:i')])->all(),
            'documents' => $order->invoices()->orderBy('issued_at')->orderBy('id')->get()
                ->filter(fn ($invoice): bool => $this->documents->available($invoice, $settings, $preview))
                ->map(fn ($invoice): array => ['number' => $invoice->number, 'type' => $invoice->document_type->label(),
                    'date' => $invoice->issue_date?->format('d.m.Y'), 'url' => $url($base.'.document', ['invoice' => $invoice->id])])->values()->all(),
        ];
    }

    private function deliveryAddress(Order $order, bool $masked): object
    {
        $point = $order->pickupPointData();
        if (collect((array) $point)->contains(fn ($value): bool => trim((string) $value) !== '')) {
            return $this->personalFields((object) [
                'name' => $point->name,
                'point_id' => $point->id,
                'street' => $point->address,
                'postal_code' => $point->postal_code,
                'city' => $point->city,
            ], $masked, $order->customer_phone);
        }

        $phone = PhoneNumberFormatter::normalize($order->shipping_phone) !== null ? $order->shipping_phone : $order->customer_phone;

        return $this->personalFields($order->shippingAddressData(), $masked, $phone);
    }

    private function personalFields(object $address, bool $masked, ?string $phone = null): object
    {
        $data = [];
        foreach (['name', 'company_name', 'point_id', 'street', 'city'] as $field) {
            $value = trim((string) ($address->{$field} ?? ''));
            $data[$field] = $masked ? $this->maskInitials($value) : $value;
        }
        foreach (['tax_id', 'building_number', 'apartment_number', 'postal_code'] as $field) {
            $value = trim((string) ($address->{$field} ?? ''));
            $data[$field] = $masked && $value !== '' ? ($field === 'postal_code' ? '******' : '****') : $value;
        }
        $data['country_name'] = $this->countries->name($address->country_code ?? null) ?? '';
        $data += $this->phone($phone, $masked);

        return (object) $data;
    }

    private function maskInitials(string $value): string
    {
        return preg_replace_callback('/[^\s\p{Z}-]+/u', static function (array $match): string {
            return preg_match('/^\p{L}\p{M}*/u', $match[0], $initial) === 1 ? $initial[0].'****' : '****';
        }, $value) ?? '****';
    }

    /** @return array{phone: string, phone_url: ?string} */
    private function phone(?string $value, bool $masked): array
    {
        $phone = PhoneNumberFormatter::normalize($value) ?? '';
        if ($phone === '') {
            return ['phone' => '', 'phone_url' => null];
        }
        if ($masked) {
            $display = '****';
            if (preg_match('/^(\+\d{1,3})\s+(.+)$/D', $phone, $parts) === 1) {
                $subscriber = preg_replace('/\D/', '', $parts[2]);
                if (strlen($subscriber) >= 7) {
                    $display = $parts[1].' ******'.substr($subscriber, -3);
                }
            }

            return ['phone' => $display, 'phone_url' => null];
        }
        $digits = preg_replace('/[^0-9+]/', '', $phone);

        return ['phone' => $phone, 'phone_url' => preg_match('/^\+[0-9]{7,15}$/D', $digits) === 1 ? 'tel:'.$digits : null];
    }
}
