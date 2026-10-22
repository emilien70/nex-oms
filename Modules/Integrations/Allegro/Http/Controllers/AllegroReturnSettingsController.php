<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroReturnSettingsRequest;
use Modules\Integrations\Allegro\Models\AllegroAccount;

class AllegroReturnSettingsController extends Controller
{
    public function update(UpdateAllegroReturnSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($data['identity'] !== $account->orderImportIdentity() || (int) $data['configuration_revision'] !== $account->returns_revision) {
                throw ValidationException::withMessages(['returns_settings' => 'Konto lub konfiguracja zmieniły się. Odśwież stronę.']);
            }
            if ($data['returns_enabled'] && ! $account->isOperational()) {
                throw ValidationException::withMessages(['returns_enabled' => 'Najpierw połącz konto Allegro i wykonaj test połączenia.']);
            }
            $account->forceFill([
                'returns_enabled' => (bool) $data['returns_enabled'],
                'returns_from_at' => CarbonImmutable::parse($data['returns_from'], config('app.timezone'))->startOfDay()->utc(),
                'returns_identity' => $account->orderImportIdentity(), 'returns_revision' => $account->returns_revision + 1,
                'returns_scan_offset' => 0, 'returns_scan_until' => null, 'returns_last_scan_at' => null, 'returns_error' => null,
            ])->save();
        }, 3);

        return redirect()->route('integrations.allegro.edit', ['tab' => 'returns'])->with('success', 'Ustawienia pobierania zwrotów zapisane.');
    }
}
