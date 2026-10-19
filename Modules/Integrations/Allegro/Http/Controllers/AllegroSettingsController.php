<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroClient;
use Modules\Integrations\Allegro\Services\AllegroOAuthService;

class AllegroSettingsController extends Controller
{
    private const SESSION_KEY = 'allegro_account_device_authorization';

    public function edit(AllegroAccountService $accounts): View
    {
        return view('integrations.allegro.edit', [
            'account' => $accounts->get(),
            'requiredScopes' => AllegroAccount::REQUIRED_SCOPES,
        ]);
    }

    public function update(Request $request, AllegroAccountService $accounts): RedirectResponse
    {
        $accounts->update($this->validated($request));

        return back()->with('success', 'Ustawienia konta Allegro zostały zapisane.');
    }

    public function startDeviceAuthorization(
        Request $request,
        AllegroAccountService $accounts,
        AllegroOAuthService $oauth,
    ): RedirectResponse {
        $account = $accounts->update($this->validated($request));

        try {
            $request->session()->put(self::SESSION_KEY, $oauth->start($account));

            return back()->with('allegro_device_started', true);
        } catch (AllegroApiException $exception) {
            return back()->withErrors(['allegro_device' => $exception->getMessage()])
                ->withInput($request->except('client_secret'));
        }
    }

    public function pollDeviceAuthorization(
        Request $request,
        AllegroOAuthService $oauth,
        AllegroClient $client,
        AllegroAccountService $accounts,
    ): JsonResponse {
        $authorization = $request->session()->get(self::SESSION_KEY);

        if (! is_array($authorization) || blank($authorization['device_code'] ?? null)) {
            return response()->json(['status' => 'expired', 'message' => 'Brak aktywnego łączenia z Allegro.'], 410);
        }

        if ((int) ($authorization['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json(['status' => 'expired', 'message' => 'Kod łączenia z Allegro wygasł.'], 410);
        }

        $account = AllegroAccount::query()->findOrFail((int) $authorization['account_id']);

        try {
            $result = $oauth->poll($account, (string) $authorization['device_code']);

            if (($result['slow_down'] ?? false) === true) {
                $authorization['interval'] = (int) ($authorization['interval'] ?? 5) + 5;
                $request->session()->put(self::SESSION_KEY, $authorization);
            }

            if (($result['status'] ?? null) !== 'authorized') {
                return response()->json($result + ['interval' => (int) ($authorization['interval'] ?? 5)]);
            }

            $account->refresh();
            $test = $client->testConnection($account);
            $accounts->markConnected($account, $test['profile']);
            $request->session()->forget(self::SESSION_KEY);

            return response()->json(['status' => 'connected']);
        } catch (AllegroApiException $exception) {
            $accounts->markConnectionError($account, $exception->getMessage());
            $request->session()->forget(self::SESSION_KEY);

            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
    }

    public function cancelDeviceAuthorization(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back();
    }

    public function testConnection(
        AllegroAccountService $accounts,
        AllegroClient $client,
    ): RedirectResponse {
        $account = $accounts->get();

        try {
            $test = $client->testConnection($account);
            $account = $accounts->markConnected($account, $test['profile']);

            return back()->with(
                'allegro_connection_success',
                'Połączenie z kontem '.($account->allegro_login ?: $account->name).' działa poprawnie.',
            );
        } catch (AllegroApiException $exception) {
            $accounts->markConnectionError($account, $exception->getMessage());

            return back()->withErrors(['allegro_connection' => $exception->getMessage()]);
        }
    }

    public function disconnect(
        Request $request,
        AllegroAccountService $accounts,
    ): RedirectResponse {
        $accounts->disconnect($accounts->get());
        $request->session()->forget(self::SESSION_KEY);

        return back()->with('success', 'Konto Allegro zostało rozłączone. Dotychczasowe dane OMS pozostają bez zmian.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'min:8'],
        ]);
    }
}
