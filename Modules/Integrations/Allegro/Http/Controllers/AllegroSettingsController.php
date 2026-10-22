<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroClient;
use Modules\Integrations\Allegro\Services\AllegroOAuthService;
use Modules\Integrations\Allegro\Services\AllegroOrderPreviewService;

class AllegroSettingsController extends Controller
{
    private const SESSION_KEY = 'allegro_account_device_authorization';

    public function edit(Request $request, AllegroAccountService $accounts, AllegroOrderPreviewService $previews): View
    {
        $account = $accounts->get();
        $tab = in_array($request->query('tab'), ['orders', 'statuses', 'shipments', 'invoices', 'returns'], true) ? $request->query('tab') : 'connection';
        $preview = null;
        $previewError = null;
        $identifier = is_string($request->query('order_identifier')) ? strtolower(trim($request->query('order_identifier'))) : '';
        if ($tab === 'orders' && $identifier !== '') {
            $request->session()->forget('allegro_order_preview');
            if (! Str::isUuid($identifier)) {
                $previewError = 'Wpisz prawidłowy identyfikator zamówienia Allegro (UUID).';
            } else {
                try {
                    $preview = $previews->preview($account, $identifier);
                    $request->session()->put('allegro_order_preview', [
                        'account_id' => $account->id,
                        'seller_user_id' => $account->allegro_user_id,
                        'environment' => $account->environment,
                        'identifier' => $identifier,
                        'hash' => $preview['source_hash'],
                        'expires_at' => now()->addMinutes(15)->timestamp,
                    ]);
                } catch (AllegroApiException|AllegroImportException $exception) {
                    $previewError = $exception->getMessage();
                }
            }
        }

        return view('integrations.allegro.edit', [
            'account' => $account,
            'requiredScopes' => AllegroAccount::REQUIRED_SCOPES,
            'tab' => $tab,
            'orderIdentifier' => $identifier,
            'orderPreview' => $preview,
            'orderPreviewError' => $previewError,
            'importOrders' => $this->recentImportOrders($account),
            'omsStatuses' => in_array($tab, ['statuses', 'shipments', 'invoices'], true) ? OrderStatusSetting::orderedSettings() : collect(),
            'fulfillmentStatuses' => AllegroFulfillmentStatus::cases(),
            'outboundMappings' => $tab === 'statuses'
                ? $account->outboundStatusMappings()->where('environment', $account->environment)
                    ->where('seller_user_id', (string) $account->allegro_user_id)->get()->keyBy('order_status_setting_id')
                : collect(),
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

    private function recentImportOrders(AllegroAccount $account): Collection
    {
        $events = AllegroOrderImportEvent::query()
            ->where('environment', $account->environment)
            ->where('seller_user_id', $account->allegro_user_id)
            ->where('allegro_account_id', $account->id);

        $groups = (clone $events)->select('external_order_id')
            ->selectRaw('MAX(updated_at) AS last_updated_at, MAX(id) AS last_event_id')
            ->selectRaw('MAX(CASE WHEN status = ? THEN id ELSE NULL END) AS failed_event_id', ['failed'])
            ->selectRaw('MAX(CASE WHEN status = ? THEN 1 ELSE 0 END) AS has_pending', ['pending'])
            ->groupBy('external_order_id')
            ->orderByDesc('last_updated_at')->orderByDesc('last_event_id')->limit(10);

        // Choose the latest source event, not an older event retried more recently.
        $latestEvent = (clone $events)->select('id')
            ->whereColumn('external_order_id', 'recent_orders.external_order_id')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(1);
        $recentOrders = DB::query()->fromSub($groups, 'recent_orders')
            ->select('recent_orders.*')->selectSub($latestEvent, 'latest_event_id')
            ->orderByDesc('last_updated_at')->orderByDesc('last_event_id')->get();
        $eventIds = $recentOrders->pluck('latest_event_id')
            ->merge($recentOrders->pluck('failed_event_id'))->filter()->unique();
        $selectedEvents = (clone $events)->whereKey($eventIds)->get()->keyBy('id');

        return $recentOrders->map(function (object $row) use ($selectedEvents): ?array {
            $latest = $selectedEvents->get($row->latest_event_id);
            if (! $latest) {
                return null;
            }
            $failed = $selectedEvents->get($row->failed_event_id);
            if ($failed?->status !== 'failed') {
                $failed = null;
            }

            return [
                'latest_event' => $latest,
                'state' => $failed ? 'failed' : ($row->has_pending ? 'pending' : $latest->status),
                'last_error' => $failed?->last_error,
                'retry_event' => $failed,
                'updated_at' => CarbonImmutable::parse($row->last_updated_at),
            ];
        })->filter()->values();
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
