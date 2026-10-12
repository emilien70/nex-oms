<?php

namespace Modules\Integrations\NexPrinter\Services;

use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;

class NexPrinterRegistry
{
    /** @param array<int, array{system_name: string, display_name: string, is_default?: bool, capabilities?: array<mixed>|null}> $printers */
    public function synchronize(NexPrinterAgent $agent, array $printers): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($agent, $printers): void {
            $now = now();
            $isFirstSynchronization = ! $agent->printers()->exists();
            $agent->printers()->update([
                'is_available' => false,
                'is_default' => false,
            ]);

            foreach ($printers as $printerData) {
                $printer = $agent->printers()->firstOrNew([
                    'system_name' => $printerData['system_name'],
                ]);

                if (! $printer->exists) {
                    $printer->is_enabled = false;
                }

                $printer->fill([
                    'display_name' => $printerData['display_name'],
                    'is_available' => true,
                    'capabilities' => $printerData['capabilities'] ?? null,
                    'last_seen_at' => $now,
                ])->save();
            }

            $agent->forceFill(['last_seen_at' => $now])->save();

            if ($isFirstSynchronization) {
                $this->preferredPrinter($agent, $printers, enabledOnly: false)?->forceFill([
                    'is_enabled' => true,
                ])->save();
            }

        });
    }

    public function setEnabled(NexPrinter $printer, bool $isEnabled): void
    {
        $printer->loadMissing('agent');

        if (! $printer->agent->is_active || $printer->agent->revoked_at !== null) {
            throw new \Modules\Integrations\NexPrinter\Exceptions\NexPrinterException(
                'printer_agent_inactive',
                'Nie można zmienić ustawień drukarki odłączonego programu.',
            );
        }

        $printer->forceFill([
            'is_enabled' => $isEnabled,
            'is_default' => false,
        ])->save();
    }

    /** @param array<int, array{system_name: string, display_name: string, is_default?: bool, capabilities?: array<mixed>|null}> $printers */
    private function preferredPrinter(
        NexPrinterAgent $agent,
        array $printers,
        bool $enabledOnly = true,
    ): ?NexPrinter {
        $preferredSystemName = collect($printers)
            ->firstWhere('is_default', true)['system_name'] ?? null;
        $preferred = $preferredSystemName !== null
            ? $agent->printers()
                ->where('system_name', $preferredSystemName)
                ->where('is_available', true)
                ->when($enabledOnly, fn ($query) => $query->where('is_enabled', true))
                ->first()
            : null;

        return $preferred
            ?? $agent->printers()
                ->where('is_available', true)
                ->when($enabledOnly, fn ($query) => $query->where('is_enabled', true))
                ->orderBy('id')
                ->first();
    }
}
