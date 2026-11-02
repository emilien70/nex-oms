<?php

namespace Modules\Integrations\VonHalsky\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Http\Requests\ImportVonHalskyOrderRequest;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;

class VonHalskyOrderImportController extends Controller
{
    public function __invoke(ImportVonHalskyOrderRequest $request, VonHalskyAccountService $accounts,
        VonHalskyOrderImportService $importer): RedirectResponse
    {
        $account = $accounts->get();
        $data = $request->validated();
        $proof = $request->session()->get('von_halsky_order_preview');
        $target = route('integrations.von-halsky.edit', ['tab' => 'orders']);
        if (! is_array($proof) || ($proof['expires_at'] ?? 0) <= now()->timestamp
            || ($proof['account_id'] ?? null) !== $account->id
            || ($proof['lock_version'] ?? null) !== $account->lock_version
            || ($proof['connection_revision'] ?? null) !== $account->connection_revision
            || ($proof['identifier'] ?? null) !== $data['order_identifier']
            || ! hash_equals((string) ($proof['hash'] ?? ''), $data['preview_hash'])) {
            return redirect($target)->withErrors(['von_halsky_import' => 'Najpierw pobierz aktualny podgląd i potwierdź import.']);
        }
        try {
            $order = $importer->import($account, $data['order_identifier'], $data['preview_hash'], $data['quantities'],
                $request->boolean('confirmed_quantities'));
        } catch (VonHalskyApiException $exception) {
            return redirect($target)->withErrors(['von_halsky_import' => $exception->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('success', 'Zamówienie Von Halsky jest dostępne w OMS.');
    }
}
