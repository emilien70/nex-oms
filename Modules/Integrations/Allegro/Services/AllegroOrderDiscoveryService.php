<?php

namespace Modules\Integrations\Allegro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;

class AllegroOrderDiscoveryService
{
    public function __construct(private readonly AllegroClient $client) {}

    public function discover(int $accountId): void
    {
        $account = AllegroAccount::query()->find($accountId);
        if (! $account?->order_import_enabled || ! $account->isOperational()) {
            return;
        }
        if ($account->order_import_identity !== $account->orderImportIdentity()) {
            throw new \RuntimeException('Zmieniono konto lub srodowisko Allegro. Wlacz pobieranie ponownie dla nowego polaczenia.');
        }
        if (($account->last_order_scan_at ?? $account->order_import_started_at)?->lt(now()->subDays(59))) {
            throw new \RuntimeException('Przerwa w odczycie przekracza bezpieczny okres dziennika Allegro. Wymagane uzupelnienie historii; kursor nie zostal zresetowany.');
        }
        // Keep a scan below the database queue's reservation timeout (90 seconds).
        $cursor = $account->order_event_cursor;
        $response = $this->client->orderEvents($account, $cursor);
        if (! is_array($response['events'] ?? null) || ! array_is_list($response['events']) || count($response['events']) > 100) {
            throw new \RuntimeException('Allegro zwrocilo nieprawidlowy dziennik zdarzen.');
        }
        $events = $response['events'];
        DB::transaction(function () use ($account, $cursor, $events): void {
            $locked = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $locked->order_import_enabled || $locked->orderImportIdentity() !== $account->order_import_identity
                || $locked->order_event_cursor !== $cursor) {
                throw new \RuntimeException('Zmienil sie kontekst odczytu Allegro. Ponow sprawdzenie.');
            }
            $last = $cursor;
            foreach ($events as $event) {
                $eventId = $event['id'] ?? null;
                $identifier = data_get($event, 'order.checkoutForm.id');
                if (! is_string($eventId) || preg_match('/^[0-9]{1,100}$/', $eventId) !== 1
                    || ! is_string($identifier) || ! Str::isUuid($identifier)
                    || (string) data_get($event, 'order.seller.id') !== $locked->allegro_user_id
                    || ! is_string($event['occurredAt'] ?? null)) {
                    throw new \RuntimeException('Nieprawidlowe zdarzenie lub tozsamosc sprzedawcy Allegro.');
                }
                $occurred = CarbonImmutable::parse($event['occurredAt']);
                AllegroOrderImportEvent::query()->firstOrCreate([
                    'environment' => $locked->environment, 'seller_user_id' => $locked->allegro_user_id, 'external_event_id' => $eventId,
                ], [
                    'allegro_account_id' => $locked->id, 'external_order_id' => strtolower($identifier),
                    'event_type' => (string) ($event['type'] ?? 'UNKNOWN'), 'occurred_at' => $occurred,
                ]);
                $last = $eventId;
            }
            if ($events !== [] && $last === $cursor) {
                throw new \RuntimeException('Dziennik Allegro nie przesunal kursora.');
            }
            $locked->update(['order_event_cursor' => $last, 'last_order_scan_at' => now(), 'last_order_import_error' => null]);
        }, 3);
        $account->refresh();
        $this->dispatchPending($account);
    }

    public function dispatchPending(AllegroAccount $account): void
    {
        AllegroOrderImportEvent::query()->where('environment', $account->environment)->where('seller_user_id', $account->allegro_user_id)
            ->where('allegro_account_id', $account->id)->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 4)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(100)->get()->each(fn ($event) => SyncAllegroOrderJob::dispatch($event->id));
    }
}
