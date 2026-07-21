<?php

namespace Modules\Shipments\Services;

use Illuminate\Support\Carbon;
use Modules\Shipments\Models\IntegrationApiLog;

class IntegrationApiLogPruner
{
    public function prune(?Carbon $now = null): array
    {
        $now ??= now();
        $retention = config('services.inpost.api_log_retention', []);

        return [
            'successful_status' => IntegrationApiLog::query()
                ->where('successful', true)
                ->where('operation', 'get_shipment')
                ->where('created_at', '<', $now->copy()->subDays(max(1, (int) ($retention['successful_status_days'] ?? 45))))
                ->delete(),
            'successful_other' => IntegrationApiLog::query()
                ->where('successful', true)
                ->where('operation', '!=', 'get_shipment')
                ->where('created_at', '<', $now->copy()->subDays(max(1, (int) ($retention['successful_days'] ?? 180))))
                ->delete(),
            'failed' => IntegrationApiLog::query()
                ->where('successful', false)
                ->where('created_at', '<', $now->copy()->subDays(max(1, (int) ($retention['failed_days'] ?? 365))))
                ->delete(),
        ];
    }
}
