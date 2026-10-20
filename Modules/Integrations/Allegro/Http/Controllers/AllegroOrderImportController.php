<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Http\Requests\ImportAllegroOrderRequest;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroOrderImportService;

class AllegroOrderImportController extends Controller
{
    public function __invoke(ImportAllegroOrderRequest $request, AllegroAccountService $accounts, AllegroOrderImportService $importer): RedirectResponse
    {
        $data = $request->validated();
        $account = $accounts->get();
        $proof = $request->session()->get('allegro_order_preview');
        $target = route('integrations.allegro.edit', ['tab' => 'orders', 'order_identifier' => $data['order_identifier']]);

        if (! is_array($proof) || ($proof['expires_at'] ?? 0) <= now()->timestamp
            || ($proof['account_id'] ?? null) !== $account->id
            || ($proof['seller_user_id'] ?? null) !== $account->allegro_user_id
            || ($proof['environment'] ?? null) !== $account->environment
            || ($proof['identifier'] ?? null) !== $data['order_identifier']
            || ! hash_equals((string) ($proof['hash'] ?? ''), $data['preview_hash'])) {
            return redirect($target)->withErrors(['allegro_order_import' => 'Najpierw pobierz aktualny podgląd zamówienia i potwierdź import.']);
        }

        try {
            $order = $importer->import($account, $data['order_identifier'], $data['preview_hash']);

            return redirect()->route('orders.show', $order)->with('success', 'Zamówienie Allegro jest dostępne w OMS.');
        } catch (AllegroApiException|AllegroImportException $exception) {
            return redirect($target)->withErrors(['allegro_order_import' => $exception->getMessage()]);
        }
    }
}
