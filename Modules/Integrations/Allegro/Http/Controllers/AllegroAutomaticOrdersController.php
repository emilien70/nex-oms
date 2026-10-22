<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroOrdersJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroClient;

class AllegroAutomaticOrdersController extends Controller
{
    public function settings(Request $request, AllegroAccountService $accounts, AllegroClient $client): RedirectResponse
    {
        $data = $request->validate(['order_import_enabled' => ['required', 'boolean']]);
        $account = $accounts->get();
        $enabled = (bool) $data['order_import_enabled'];
        $initialize = $enabled && ($account->order_import_started_at === null || $account->order_import_identity !== $account->orderImportIdentity());
        $started = now();
        if ($enabled && ! $account->isOperational()) {
            throw ValidationException::withMessages(['order_import_enabled' => 'Najpierw polacz konto Allegro i sprawdz polaczenie.']);
        }
        try {
            $stats = $initialize ? $client->eventStats($account) : null;
        } catch (AllegroApiException $exception) {
            throw ValidationException::withMessages(['order_import_enabled' => $exception->getMessage()]);
        }
        if ($initialize && (! array_key_exists('latestEvent', $stats) || ($stats['latestEvent'] !== null && ! is_array($stats['latestEvent'])))) {
            throw ValidationException::withMessages(['order_import_enabled' => 'Nieprawidlowy punkt poczatkowy dziennika Allegro.']);
        }
        $cursor = $initialize ? data_get($stats, 'latestEvent.id') : $account->order_event_cursor;
        if (($initialize && $stats['latestEvent'] !== null && $cursor === null)
            || ($cursor !== null && (! is_string($cursor) || preg_match('/^[0-9]{1,100}$/', $cursor) !== 1))) {
            throw ValidationException::withMessages(['order_import_enabled' => 'Allegro zwrocilo nieprawidlowy kursor zdarzen.']);
        }
        DB::transaction(function () use ($account, $enabled, $initialize, $started, $cursor): void {
            $locked = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($locked->orderImportIdentity() === $account->orderImportIdentity(), 409);
            $locked->order_import_enabled = $enabled;
            if ($initialize) {
                $locked->fill(['order_import_identity' => $locked->orderImportIdentity(), 'order_import_started_at' => $started,
                    'order_event_cursor' => $cursor, 'last_order_scan_at' => null, 'last_order_import_error' => null]);
            }
            $locked->save();
        }, 3);

        return $this->back()->with('success', 'Ustawienia automatycznego pobierania zostaly zapisane.');
    }

    public function scan(AllegroAccountService $accounts): RedirectResponse
    {
        $account = $this->active($accounts);
        DiscoverAllegroOrdersJob::dispatch($account->id);

        return $this->back()->with('success', 'Zlecono sprawdzenie zamowien Allegro.');
    }

    public function retry(AllegroOrderImportEvent $allegroOrderImportEvent, AllegroAccountService $accounts): RedirectResponse
    {
        $account = $this->active($accounts);
        abort_unless($allegroOrderImportEvent->allegro_account_id === $account->id
            && $allegroOrderImportEvent->environment === $account->environment && $allegroOrderImportEvent->seller_user_id === $account->allegro_user_id, 404);
        if ($allegroOrderImportEvent->status !== 'processed') {
            $allegroOrderImportEvent->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);
            SyncAllegroOrderJob::dispatch($allegroOrderImportEvent->id);
        }

        return $this->back()->with('success', 'Zlecono ponowienie pobrania.');
    }

    public function historyPreview(Request $request, AllegroAccountService $accounts, AllegroClient $client): RedirectResponse
    {
        $account = $this->active($accounts);
        $data = $request->validate(['history_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->subDays(60)->toDateString(), 'before_or_equal:today']]);
        $from = CarbonImmutable::parse($data['history_from'])->startOfDay();
        $ids = [];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $rows = $client->incompleteOrders($account, $offset, $from->utc()->toIso8601String())['checkoutForms'] ?? null;
            if (! is_array($rows)) {
                throw ValidationException::withMessages(['history_from' => 'Nieprawidlowy wynik odczytu historii Allegro.']);
            }
            foreach ($rows as $row) {
                $date = data_get($row, 'lineItems.0.boughtAt');
                if (is_string($date) && CarbonImmutable::parse($date)->gte($from) && Str::isUuid($row['id'] ?? '')) {
                    $ids[] = strtolower($row['id']);
                }
            }
            if (count($rows) < 100) {
                break;
            }
            if ($offset === 900) {
                throw ValidationException::withMessages(['history_from' => 'Historia zawiera ponad 1000 niepelnych zakupow. Pobierz wybrane transakcje recznie; niczego nie zaimportowano.']);
            }
        }
        $request->session()->put('allegro_history_preview', ['identity' => $account->orderImportIdentity(), 'account_id' => $account->id,
            'identifiers' => array_values(array_unique($ids)), 'from' => $data['history_from'], 'expires_at' => now()->addMinutes(15)->timestamp]);

        return $this->back();
    }

    public function historyImport(Request $request, AllegroAccountService $accounts): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $account = $this->active($accounts);
        $preview = $request->session()->get('allegro_history_preview');
        abort_unless(is_array($preview) && $preview['account_id'] === $account->id
            && $preview['identity'] === $account->orderImportIdentity() && $preview['expires_at'] > now()->timestamp, 409);
        foreach ($preview['identifiers'] as $identifier) {
            $event = AllegroOrderImportEvent::query()->firstOrCreate(['environment' => $account->environment,
                'seller_user_id' => $account->allegro_user_id, 'external_event_id' => 'history:'.$identifier], [
                    'allegro_account_id' => $account->id, 'external_order_id' => $identifier, 'event_type' => 'HISTORICAL_INCOMPLETE', 'occurred_at' => now(),
                ]);
            SyncAllegroOrderJob::dispatch($event->id);
        }
        $request->session()->forget('allegro_history_preview');

        return $this->back()->with('success', 'Zlecono pobranie wskazanych zakupow niepelnych.');
    }

    private function active(AllegroAccountService $accounts): AllegroAccount
    {
        $account = $accounts->get();
        if (! $account->order_import_enabled || ! $account->isOperational() || $account->order_import_identity !== $account->orderImportIdentity()) {
            throw ValidationException::withMessages(['order_import_enabled' => 'Wlacz poprawnie skonfigurowane automatyczne pobieranie Allegro.']);
        }

        return $account;
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('integrations.allegro.edit', ['tab' => 'orders']);
    }
}
