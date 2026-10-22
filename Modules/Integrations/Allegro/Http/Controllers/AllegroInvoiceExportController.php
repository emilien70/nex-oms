<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportService;
use Modules\Invoices\Models\Invoice;

class AllegroInvoiceExportController extends Controller
{
    public function store(Request $request, Order $order, Invoice $invoice, AllegroAccountService $accounts, AllegroInvoiceExportService $exports): RedirectResponse
    {
        abort_unless($invoice->order_id === $order->id, 404);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'expected_lock_version' => ['required', 'integer', 'min:0']]);
        if ($invoice->lock_version !== (int) $data['expected_lock_version']) {
            throw ValidationException::withMessages(['allegro_invoice_export' => 'Dokument zmienił się. Odśwież kartę zamówienia.']);
        }
        $link = $order->allegroOrderLink;
        abort_unless($link && $link->allegro_account_id === $accounts->get()->id, 404);
        $export = $exports->enqueue($invoice->id, true);
        if (! $export) {
            throw ValidationException::withMessages(['allegro_invoice_export' => 'Nie można przekazać tego dokumentu. Sprawdź zamknięcie Korekty, powiązanie zamówienia i połączenie Allegro.']);
        }
        if (in_array($export->state, ['waiting', 'failed', 'skipped'], true)) {
            $exports->retry($export->id);
        }

        return redirect()->route('orders.show', $order)->with('success', 'Zlecono obsługę przekazania dokumentu do Allegro.');
    }

    public function retry(Request $request, Order $order, AllegroInvoiceExport $export, AllegroAccountService $accounts, AllegroInvoiceExportService $exports): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $account = $accounts->get();
        abort_unless($export->order_id === $order->id && $export->allegro_account_id === $account->id
            && $export->environment === $account->environment && $export->seller_user_id === $account->allegro_user_id, 404);
        if (! $exports->retry($export->id)) {
            throw ValidationException::withMessages(['allegro_invoice_export' => 'Nie można ponowić tej operacji. Dokument mógł się zmienić albo konto nie jest połączone.']);
        }

        return redirect()->route('orders.show', $order)->with('success', $export->upload_started_at
            ? 'Zlecono sprawdzenie dokumentu w Allegro bez ponownego przesyłania pliku.' : 'Zlecono ponowienie przekazania dokumentu.');
    }
}
