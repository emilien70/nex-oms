<?php

namespace Modules\Shipments\Services;

use App\Models\Order;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Integrations\InPost\Services\InPostShipmentServiceResolver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

class ShipmentFormDefaultsService
{
    public function __construct(
        private readonly InPostShipmentServiceResolver $lockerServiceResolver,
        private readonly InPostCourierServiceResolver $courierServiceResolver,
    ) {}

    public function for(Order $order, CourierAccount $account): array
    {
        $codAmount = $order->cash_on_delivery
            ? max((float) $order->total_gross - (float) $order->paid_amount, 0)
            : 0;

        return match ($account->provider) {
            CourierAccount::PROVIDER_INPOST_LOCKERS => [
                'service' => $this->lockerServiceResolver->defaultFor($order),
                'parcel_template' => (string) $account->setting('default_parcel_template', 'medium'),
                'target_point_id' => (string) ($order->pickup_point_id ?? ''),
                'cod_amount' => $codAmount > 0 ? $this->decimal($codAmount) : '',
                'insurance_amount' => '',
                'content_description' => $this->contentDescription($order, $account),
                'sending_method' => (string) $account->setting('sending_method', 'dispatch_order'),
                'additional_services' => [],
            ],
            CourierAccount::PROVIDER_INPOST_COURIER => [
                'service' => $this->courierServiceResolver->resolve($account, null),
                'cod_amount' => $codAmount > 0 ? $this->decimal($codAmount) : '',
                'insurance_amount' => $this->decimal(max(
                    (float) $account->setting('default_insurance_amount', 0),
                    $codAmount,
                )),
                'content_description' => $this->contentDescription($order, $account),
                'additional_services' => $this->courierAdditionalServices($account),
                'parcel' => [
                    'weight' => $this->measurement((float) $account->setting('default_weight', 1)),
                    'length' => $this->measurement((float) $account->setting('default_length', 25)),
                    'width' => $this->measurement((float) $account->setting('default_width', 20)),
                    'height' => $this->measurement((float) $account->setting('default_height', 10)),
                    'is_non_standard' => false,
                ],
            ],
            CourierAccount::PROVIDER_DPD => [
                'service' => (string) $account->setting('default_service', Shipment::SERVICE_DPD_DOMESTIC),
                'cod_amount' => $codAmount > 0 ? $this->decimal($codAmount) : '',
                'insurance_amount' => $this->decimal((float) $account->setting('default_insurance_amount', 0)),
                'content_description' => $this->contentDescription($order, $account),
                'additional_services' => collect([
                    Shipment::ADDITIONAL_SERVICE_SATURDAY => (bool) $account->setting('default_saturday', false),
                    Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => (bool) $account->setting('default_return_documents', false),
                ])->filter()->keys()->all(),
                'parcel' => [
                    'weight' => $this->measurement((float) $account->setting('default_weight', 1)),
                    'length' => $this->measurement((float) $account->setting('default_length', 25)),
                    'width' => $this->measurement((float) $account->setting('default_width', 20)),
                    'height' => $this->measurement((float) $account->setting('default_height', 10)),
                ],
            ],
            default => [],
        };
    }

    private function contentDescription(Order $order, CourierAccount $account): string
    {
        $description = match ($account->setting('content_description_source', 'order_id')) {
            'customer_login' => $order->customer_login,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            default => OrderReferenceFormatter::format($order->id),
        };

        return mb_substr(trim((string) $description), 0, 100);
    }

    private function courierAdditionalServices(CourierAccount $account): array
    {
        return collect([
            Shipment::ADDITIONAL_SERVICE_SMS => (bool) $account->setting('default_sms', false),
            Shipment::ADDITIONAL_SERVICE_EMAIL => (bool) $account->setting('default_email', false),
            Shipment::ADDITIONAL_SERVICE_SATURDAY => (bool) $account->setting('default_saturday', false),
            Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => (bool) $account->setting('default_return_documents', false),
        ])->filter()->keys()->all();
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function measurement(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
