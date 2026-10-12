<?php

namespace Modules\Integrations\NexPrinter\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\NexPrinter\Enums\NexPrintJobStatus;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Modules\Integrations\NexPrinter\Models\NexPrintJob;

class NexPrinterAgentRemovalService
{
    public function remove(NexPrinterAgent $agent): void
    {
        DB::transaction(function () use ($agent): void {
            $agent->refresh();

            if ($agent->is_active) {
                throw new NexPrinterException(
                    'agent_must_be_disconnected',
                    'Przed usunięciem komputera należy odłączyć program NEX Printer.',
                );
            }

            $agent->printJobs()
                ->whereIn('status', [
                    NexPrintJobStatus::Queued->value,
                    NexPrintJobStatus::Claimed->value,
                    NexPrintJobStatus::Downloaded->value,
                ])
                ->lockForUpdate()
                ->get()
                ->each(function (NexPrintJob $job): void {
                    $job->forceFill([
                        'status' => NexPrintJobStatus::Cancelled,
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => 'agent_removed',
                        'last_error_message' => 'Komputer NEX Printer został usunięty z OMS.',
                    ])->save();
                    $job->events()->create([
                        'event_type' => 'cancelled',
                        'status' => NexPrintJobStatus::Cancelled,
                        'actor' => 'oms',
                        'context' => ['reason' => 'agent_removed'],
                        'occurred_at' => now(),
                    ]);
                });

            $agent->delete();
        });
    }
}
