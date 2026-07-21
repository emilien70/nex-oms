<?php

namespace Modules\Integrations\DPD\Services;

use Modules\Shipments\Models\Shipment;

class DpdServiceResolver
{
    public function supportedServices(): array
    {
        return array_keys($this->serviceLabels());
    }

    public function serviceLabels(): array
    {
        return [
            Shipment::SERVICE_DPD_DOMESTIC => html_entity_decode('Przesy&#322;ka krajowa standardowa', ENT_QUOTES, 'UTF-8'),
            Shipment::SERVICE_DPD_NEXT_DAY => 'DPD Next Day',
            Shipment::SERVICE_DPD_TIME_0930 => html_entity_decode('Dor&#281;czenie do 09:30', ENT_QUOTES, 'UTF-8'),
            Shipment::SERVICE_DPD_TIME_1200 => html_entity_decode('Dor&#281;czenie do 12:00', ENT_QUOTES, 'UTF-8'),
        ];
    }

    public function transportCode(string $service): ?string
    {
        return match ($service) {
            Shipment::SERVICE_DPD_NEXT_DAY => 'DPD_NEXT_DAY',
            Shipment::SERVICE_DPD_TIME_0930 => 'TIME0930',
            Shipment::SERVICE_DPD_TIME_1200 => 'TIME1200',
            default => null,
        };
    }
}
