<?php

namespace Modules\Shipments\Services;

use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class ShipmentStatusMapper
{
    public function map(?string $provider, ?string $providerStatus): string
    {
        $status = strtolower(trim((string) $providerStatus));

        if ($provider === CourierAccount::PROVIDER_DPD) {
            return $this->mapDpdStatus($status);
        }

        if ($provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING) {
            return $this->mapAllegroStatus($status);
        }

        if (! in_array($provider, [
            CourierAccount::PROVIDER_INPOST_LOCKERS,
            CourierAccount::PROVIDER_INPOST_COURIER,
        ], true)) {
            return $this->mapCommonStatus($status);
        }

        return match (true) {
            in_array($status, [
                Shipment::STATUS_QUEUED,
                Shipment::STATUS_CREATED,
                Shipment::STATUS_CONFIRMED,
                'offers_prepared',
                'offer_selected',
            ], true) => Shipment::OMS_STATUS_CREATED,

            in_array($status, [
                'ready_to_pickup_from_pok',
                'dispatched_by_sender_to_pok',
                'dispatched_by_sender',
                'collected_from_sender',
                'taken_by_courier',
                'adopted_at_source_branch',
                'sent_from_source_branch',
                'sent_from_sorting_center',
                'readdressed',
                'taken_by_courier_from_pok',
                'unstack_from_customer_service_point',
                'taken_by_courier_from_customer_service_point',
                'unstack_from_box_machine',
                'adopted_at_sorting_center',
                'redirect_to_box',
                'in_transit',
            ], true) => Shipment::OMS_STATUS_DISPATCHED,

            in_array($status, [
                'out_for_delivery',
                'out_for_delivery_to_address',
            ], true) => Shipment::OMS_STATUS_OUT_FOR_DELIVERY,

            in_array($status, [
                'ready_to_pickup',
                'ready_to_pickup_from_branch',
                'pickup_reminder_sent',
                'pickup_reminder_sent_address',
                'avizo',
                'courier_avizo_in_customer_service_point',
                'stack_in_customer_service_point',
                'stack_in_box_machine',
            ], true) => Shipment::OMS_STATUS_READY_FOR_PICKUP,

            $status === 'delivered' => Shipment::OMS_STATUS_DELIVERED,

            in_array($status, [
                'returned_to_sender',
                'returning_to_sender',
            ], true) => Shipment::OMS_STATUS_RETURNED,

            default => Shipment::OMS_STATUS_PROBLEM,
        };
    }

    private function mapCommonStatus(string $status): string
    {
        return match ($status) {
            Shipment::OMS_STATUS_CREATED => Shipment::OMS_STATUS_CREATED,
            Shipment::OMS_STATUS_DISPATCHED => Shipment::OMS_STATUS_DISPATCHED,
            Shipment::OMS_STATUS_OUT_FOR_DELIVERY => Shipment::OMS_STATUS_OUT_FOR_DELIVERY,
            Shipment::OMS_STATUS_READY_FOR_PICKUP => Shipment::OMS_STATUS_READY_FOR_PICKUP,
            Shipment::OMS_STATUS_DELIVERED => Shipment::OMS_STATUS_DELIVERED,
            Shipment::OMS_STATUS_RETURNED => Shipment::OMS_STATUS_RETURNED,
            default => Shipment::OMS_STATUS_PROBLEM,
        };
    }

    private function mapAllegroStatus(string $status): string
    {
        return match ($status) {
            Shipment::STATUS_QUEUED,
            Shipment::STATUS_CREATED,
            Shipment::STATUS_CONFIRMED,
            'allegro_command_pending',
            'pending' => Shipment::OMS_STATUS_CREATED,

            'in_transit' => Shipment::OMS_STATUS_DISPATCHED,
            'released_for_delivery' => Shipment::OMS_STATUS_OUT_FOR_DELIVERY,
            'available_for_pickup', 'notice_left' => Shipment::OMS_STATUS_READY_FOR_PICKUP,
            'delivered' => Shipment::OMS_STATUS_DELIVERED,
            'returned' => Shipment::OMS_STATUS_RETURNED,
            'issue' => Shipment::OMS_STATUS_PROBLEM,
            default => Shipment::OMS_STATUS_PROBLEM,
        };
    }

    private function mapDpdStatus(string $status): string
    {
        // DPD InfoServices returns six-digit business codes, including leading zeros.
        // The same codes may also be stored without padding after shipment creation.
        $status = ltrim($status, '0') ?: '0';

        return match (true) {
            in_array($status, [Shipment::STATUS_QUEUED, Shipment::STATUS_CREATED, '30103'], true) => Shipment::OMS_STATUS_CREATED,

            in_array($status, [
                '40101', '40102', '50101', '50102', '120100', '120101', '120102', '120103',
                '120104', '160101', '160103', '160501', '160502', '160503', '600106', '700401',
            ], true) => Shipment::OMS_STATUS_DISPATCHED,

            in_array($status, ['170101', '170102', '170501', '500300'], true) => Shipment::OMS_STATUS_OUT_FOR_DELIVERY,

            in_array($status, [
                '410135', '502300', '511201', '600101', '600102', '612001', '701201', '703201',
            ], true) => Shipment::OMS_STATUS_READY_FOR_PICKUP,

            in_array($status, [
                '190101', '190102', '190103', '190104', '190202', '190203', '190204',
                '501300', '501304', '501340', '511901', '511902', '511903', '600103',
                '600104', '701901', '701902',
            ], true) => Shipment::OMS_STATUS_DELIVERED,

            in_array($status, [
                '230403', '230408', '500610', '500611', '500612', '500614', '500615',
                '500616', '500617', '500624', '500629', '500630', '500633', '500635',
                '500637', '500639', '500642', '500647', '500649', '500661', '500684', '500685',
            ], true) => Shipment::OMS_STATUS_RETURNED,

            default => Shipment::OMS_STATUS_PROBLEM,
        };
    }
}
