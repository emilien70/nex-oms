<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroShipmentExportService;

class AllegroShipmentExportController extends Controller
{
    public function retry(Order $order, AllegroShipmentExport $export, AllegroAccountService $accounts, AllegroShipmentExportService $exports): RedirectResponse
    {
        $account = $accounts->get();
        abort_unless($export->order_id === $order->id && $export->allegro_account_id === $account->id
            && $export->environment === $account->environment && $export->seller_user_id === $account->allegro_user_id, 404);
        if (! $exports->retry($export->id)) {
            throw ValidationException::withMessages(['allegro_shipment_export' => 'Nie można ponowić tej przesyłki. Sprawdź ustawienia, status zamówienia oraz aktualny numer nadania.']);
        }

        return redirect()->route('orders.show', $order)->with('success', 'Zlecono ponowne sprawdzenie i przekazanie numeru przesyłki.');
    }
}
