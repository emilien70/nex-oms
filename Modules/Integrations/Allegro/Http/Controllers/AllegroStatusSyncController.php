<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroOutboundStatusSyncService;

class AllegroStatusSyncController extends Controller
{
    public function retry(Order $order, AllegroAccountService $accounts, AllegroOutboundStatusSyncService $sync): RedirectResponse
    {
        $account = $accounts->get();
        $link = $order->allegroOrderLink;
        abort_unless($link && $link->allegro_account_id === $account->id
            && $link->environment === $account->environment && $link->seller_user_id === $account->allegro_user_id, 404);
        if (! $sync->retry($order->id, (string) Str::uuid())) {
            throw ValidationException::withMessages(['allegro_status_sync' => 'Nie można ponowić tej zmiany. Sprawdź aktualny status zamówienia i powiązania statusów Allegro.']);
        }

        return redirect()->route('orders.show', $order)->with('success', 'Zlecono ponowne przekazanie statusu do Allegro.');
    }
}
