<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Services\PrestashopOrderMessageService;
use Throwable;

class FetchPrestashopOrderMessages extends Command
{
    protected $signature = 'prestashop:order-comments {order : ID zamowienia OMS}';

    protected $description = 'Pobierz tylko komentarze kupujacego do wskazanego zamowienia PrestaShop';

    public function handle(PrestashopOrderMessageService $messages): int
    {
        $id = (string) $this->argument('order');
        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('Podaj poprawne ID zamowienia OMS.');

            return self::FAILURE;
        }
        $link = PrestashopOrderLink::query()->where('order_id', $id)->first();
        if (! $link || ! $link->account?->is_active || ! $link->order || $link->order->trashed() || $link->order->source !== 'prestashop') {
            $this->error('Brak aktywnego powiazania zamowienia PrestaShop.');

            return self::FAILURE;
        }

        try {
            $count = $messages->sync($link->id);
        } catch (Throwable) {
            $messages->recordFailure($link->id, 'manual');
            $this->error('Nie pobrano komentarzy. Sprawdz dostep GET do zasobu messages i log aplikacji.');

            return self::FAILURE;
        }
        $this->info('Pobrane komentarze: '.$count.'. Dane zamowienia pozostaly bez zmian.');

        return self::SUCCESS;
    }
}
