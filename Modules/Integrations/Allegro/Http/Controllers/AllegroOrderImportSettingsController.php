<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroOrderImportSettingsRequest;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;

class AllegroOrderImportSettingsController extends Controller
{
    public function __invoke(UpdateAllegroOrderImportSettingsRequest $request, AllegroAccountService $accounts): RedirectResponse
    {
        $account = $accounts->get();
        DB::transaction(function () use ($account, $request): void {
            AllegroAccount::query()->lockForUpdate()->findOrFail($account->id)->fill($request->validated())->save();
        });
        $request->session()->forget('allegro_order_preview');

        return redirect()->route('integrations.allegro.edit', ['tab' => 'orders'])->with('success', 'Ustawienia importu zostały zapisane.');
    }
}
