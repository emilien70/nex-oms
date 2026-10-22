<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;

class AllegroOrderSyncService
{
    public function __construct(
        private readonly AllegroClient $client,
        private readonly AllegroOrderPreviewService $previews,
        private readonly AllegroOrderImportService $importer,
    ) {}

    public function sync(AllegroAccount $account, string $identifier): void
    {
        $link = AllegroOrderLink::query()->where('environment', $account->environment)
            ->where('seller_user_id', $account->allegro_user_id)->where('external_order_id', $identifier)->first();
        // Tombstones and the trash remain authoritative, even after reconnecting the account.
        if ($link && (! $link->order || $link->order->trashed() || $link->import_state === 'superseded')) {
            return;
        }
        $raw = $this->client->order($account, $identifier);
        $status = $raw['status'] ?? null;
        if (! in_array($status, ['BOUGHT', 'FILLED_IN', 'READY_FOR_PROCESSING', 'CANCELLED'], true)) {
            throw new \RuntimeException('Nieznany stan zamowienia Allegro.');
        }
        if ($status === 'CANCELLED' && $link) {
            $this->cancel($link);

            return;
        }
        if ($link?->isReady()) {
            return;
        }
        if ($status === 'READY_FOR_PROCESSING') {
            $preview = $this->previews->normalize($account, $identifier, $raw);
            if (in_array(data_get($raw, 'fulfillment.status'), ['SENT', 'READY_FOR_PICKUP', 'PICKED_UP', 'CANCELLED'], true)) {
                $preview['importable'] = false;
                $preview['blockers'][] = 'Zamowienie ma juz stan realizacji '.$raw['fulfillment']['status'].'. Sprawdz je przed realizacja w OMS.';
            }
            if (! $preview['importable']) {
                $preview = $this->previews->provisional($account, $identifier, $raw, implode(' ', $preview['blockers']));
            }
        } else {
            $preview = $this->previews->provisional($account, $identifier, $raw);
        }
        $order = $this->importer->synchronize($account, $preview);
        $link = $order->allegroOrderLink()->firstOrFail();
        if ($status === 'CANCELLED') {
            $this->cancel($link);
        } else {
            $this->resolveCombinedPurchases($link);
        }
    }

    public function cancel(AllegroOrderLink $link): void
    {
        DB::transaction(function () use ($link): void {
            $managed = AllegroOrderLink::query()->lockForUpdate()->findOrFail($link->id);
            $order = Order::withTrashed()->lockForUpdate()->find($managed->order_id);
            if (! $order || $order->trashed() || in_array($managed->import_state, ['cancelled', 'superseded'], true)) {
                return;
            }
            $inProgress = $managed->completed_at !== null && ($order->status !== Order::STATUS_NEW
                || $order->invoices()->exists() || $order->shipments()->exists() || $order->shipmentCreationAttempts()->exists());
            $managed->update([
                'external_status' => 'CANCELLED', 'import_state' => 'cancelled',
                'processing_error' => $inProgress ? 'Allegro anulowalo zamowienie juz obslugiwane w OMS. Sprawdz dokumenty, wplate i przesylki.'
                    : (BigDecimal::of((string) $order->paid_amount)->isGreaterThan(0) ? 'Zamowienie anulowane z zarejestrowana wplata. Sprawdz zwrot srodkow w Allegro.' : null),
            ]);
            if (! $inProgress) {
                $order->update(['status' => Order::STATUS_CANCELLED, 'status_changed_at' => now()]);
            }
            $order->events()->create(['event_type' => 'allegro_order_cancelled', 'title' => 'Anulowano zamowienie w Allegro', 'description' => $managed->processing_error ?: 'Zakup anulowany w Allegro.']);
        }, 3);
    }

    private function resolveCombinedPurchases(AllegroOrderLink $replacement): void
    {
        $ids = $replacement->line_item_ids ?? [];
        if ($ids === []) {
            return;
        }
        AllegroOrderLink::query()->where('environment', $replacement->environment)
            ->where('seller_user_id', $replacement->seller_user_id)->where('id', '!=', $replacement->id)
            ->whereNull('completed_at')->whereIn('import_state', ['incomplete', 'review', 'cancelled'])
            ->eachById(function (AllegroOrderLink $candidate) use ($replacement, $ids): void {
                if (($candidate->line_item_ids ?? []) === [] || array_diff($candidate->line_item_ids, $ids) !== []) {
                    return;
                }
                DB::transaction(function () use ($candidate, $replacement): void {
                    $old = AllegroOrderLink::query()->lockForUpdate()->findOrFail($candidate->id);
                    $order = Order::withTrashed()->lockForUpdate()->find($old->order_id);
                    if ($old->completed_at || $old->import_state === 'superseded' || ! $order || $order->trashed()) {
                        return;
                    }
                    $old->update(['import_state' => 'superseded', 'replaced_by_link_id' => $replacement->id, 'processing_error' => null]);
                    $order->update(['status' => Order::STATUS_CANCELLED, 'status_changed_at' => now()]);
                    $order->events()->create(['event_type' => 'allegro_purchase_combined', 'title' => 'Zakup polaczony w Allegro', 'description' => 'Zastapiony zamowieniem OMS #'.$replacement->order_id.'.']);
                }, 3);
            });
    }
}
