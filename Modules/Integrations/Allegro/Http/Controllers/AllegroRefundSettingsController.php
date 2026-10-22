<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroAccount;

class AllegroRefundSettingsController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate(['identity' => ['required', 'string', 'max:255'], 'configuration_revision' => ['required', 'integer', 'min:0'],
            'refunds_enabled' => ['required', 'boolean'], 'refunds_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        DB::transaction(function () use ($data): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($data['identity'] !== $account->orderImportIdentity() || (int) $data['configuration_revision'] !== $account->refunds_revision) {
                throw ValidationException::withMessages(['refunds_settings' => 'Konto lub konfiguracja zmieniły się. Odśwież stronę.']);
            }
            if ($data['refunds_enabled'] && (! $account->isOperational() || ! in_array(AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true))) {
                throw ValidationException::withMessages(['refunds_enabled' => 'Połącz konto z uprawnieniem allegro:api:payments:read i wykonaj test połączenia.']);
            }
            $account->forceFill(['refunds_enabled' => (bool) $data['refunds_enabled'],
                'refunds_from_at' => CarbonImmutable::parse($data['refunds_from'], config('app.timezone'))->startOfDay(),
                'refunds_identity' => $account->orderImportIdentity(), 'refunds_revision' => $account->refunds_revision + 1,
                'refunds_scan_offset' => 0, 'refunds_scan_until' => null, 'refunds_last_scan_at' => null, 'refunds_retry_after' => null, 'refunds_error' => null])->save();
        }, 3);

        return redirect()->route('integrations.allegro.edit', ['tab' => 'returns'])->with('success', 'Ustawienia pobierania refundacji zapisane.');
    }
}
