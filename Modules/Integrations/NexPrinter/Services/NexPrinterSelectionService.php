<?php

namespace Modules\Integrations\NexPrinter\Services;

use Illuminate\Support\Collection;
use Modules\Integrations\NexPrinter\Models\NexPrinter;

class NexPrinterSelectionService
{
    /** @return Collection<int, NexPrinter> */
    public function availableForPdf(): Collection
    {
        return NexPrinter::query()
            ->with('agent')
            ->where('is_enabled', true)
            ->where('is_available', true)
            ->whereHas('agent', fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('revoked_at'))
            ->orderBy('display_name')
            ->orderBy('id')
            ->get()
            ->filter(static function (NexPrinter $printer): bool {
                $formats = data_get($printer->capabilities, 'formats', []);

                return is_array($formats)
                    && in_array('pdf', array_map(
                        static fn ($format): string => strtolower((string) $format),
                        $formats,
                    ), true);
            })
            ->sortBy(static fn (NexPrinter $printer): string => mb_strtolower(
                $printer->display_name.'|'.$printer->agent->name.'|'.str_pad((string) $printer->getKey(), 20, '0', STR_PAD_LEFT),
            ))
            ->values();
    }
}
