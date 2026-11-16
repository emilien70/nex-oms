<?php

namespace App\Services;

use App\Enums\OrderEmailMessageStatus;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Automation\Models\AutomationRun;
use Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderCommand;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Shipments\Models\ShipmentCreationAttempt;

class OrderArchiveService
{
    public const DELETE_LIMIT = 1000;

    private const BATCH_SIZE = 250;

    public function archiveDue(): int
    {
        if (! config('order_archive.automatic')) {
            return 0;
        }

        $now = now()->toImmutable();
        $eligible = fn (): Builder => Order::query()->operational()
            ->where(fn (Builder $query) => $query->where('purchased_at', '<', $now->subDays(90))
                ->orWhere(fn (Builder $query) => $query->whereNull('purchased_at')->where('created_at', '<', $now->subDays(90))))
            ->where(fn (Builder $query) => $query->whereNull('archive_deferred_until')->orWhere('archive_deferred_until', '<=', $now))
            ->whereDoesntHave('automationRuns', fn (Builder $query) => $query->where('status', AutomationRun::STATUS_RUNNING));

        $archived = 0;
        $eligible()->select('id')->chunkById(self::BATCH_SIZE, function ($orders) use ($eligible, $now, &$archived): void {
            foreach ($orders as $candidate) {
                $archived += DB::transaction(function () use ($candidate, $eligible, $now): int {
                    $order = Order::query()->lockForUpdate()->find($candidate->id);
                    if (! $order || ! $eligible()->whereKey($order->id)->exists()) {
                        return 0;
                    }

                    Order::query()->whereKey($order->id)->toBase()->update(['archived_at' => $now, 'archive_deferred_until' => null]);
                    $order->automationRuns()->whereIn('status', [AutomationRun::STATUS_QUEUED, AutomationRun::STATUS_WAITING])
                        ->update([
                            'status' => AutomationRun::STATUS_INTERRUPTED,
                            'finished_at' => $now,
                            'error_message' => 'Zamówienie jest w archiwum. Nie uruchomiono dalszych kroków.',
                        ]);
                    $order->events()->create(['event_type' => 'order_archived', 'title' => 'Przeniesiono do archiwum']);

                    return 1;
                }, 3);
            }
        });

        return $archived;
    }

    public function restore(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $current = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($current->isArchived()) {
                Order::query()->whereKey($current->id)->toBase()->update(['archived_at' => null, 'archive_deferred_until' => now()->addDays(7)]);
                $current->events()->create(['event_type' => 'order_unarchived', 'title' => 'Przywrócono z archiwum']);
                $current->refresh();
            }

            return $current;
        }, 3);
    }

    public function deleteMany(array $orderIds): int
    {
        return DB::transaction(function () use ($orderIds): int {
            $orders = Order::query()->whereKey($orderIds)->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count($orderIds) || $orders->contains(fn (Order $order): bool => ! $order->isArchived())) {
                throw ValidationException::withMessages([
                    'order_ids' => 'Część zaznaczonych zamówień nie jest już w Archiwum. Niczego nie usunięto.',
                ]);
            }

            $ids = $orders->modelKeys();
            // Serialize deletion with submission preparation and refund claims.
            $invoiceOrders = Invoice::query()->whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->pluck('order_id', 'id')->all();
            AllegroOrderLink::query()->whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            $this->assertNoPendingOperations($ids, $invoiceOrders);

            foreach ($orders as $order) {
                if (! $order->forceDelete()) {
                    throw ValidationException::withMessages([
                        'order_ids' => 'Nie udało się usunąć zamówienia nr '.$order->id.'. Niczego nie usunięto.',
                    ]);
                }
            }

            return $orders->count();
        }, 3);
    }

    private function assertNoPendingOperations(array $orderIds, array $invoiceOrders): void
    {
        $operations = [
            'automatyzacja' => AutomationRun::query()->where('status', AutomationRun::STATUS_RUNNING),
            'nadawanie przesyłki' => ShipmentCreationAttempt::query()->whereIn('status', [
                ShipmentCreationAttempt::STATUS_QUEUED, ShipmentCreationAttempt::STATUS_PROCESSING, ShipmentCreationAttempt::STATUS_UNKNOWN,
            ]),
            'wysyłka e-mail' => OrderEmailMessage::query()
                ->whereIn('status', [OrderEmailMessageStatus::Queued->value, OrderEmailMessageStatus::Sending->value]),
            'wysyłka SMS' => SmsMessage::query()->whereIn('status', ['queued', 'claimed', 'submitting']),
            'płatność PayNow' => PayNowPayment::query()
                ->whereIn('status', [PayNowPaymentStatus::Created->value, PayNowPaymentStatus::Pending->value]),
            'zwrot wpłaty Allegro' => AllegroRefundCommand::query()->whereIn('state', AllegroRefundCommand::RESERVING_STATES),
            'zwrot prowizji Allegro' => AllegroCommissionRefundClaim::query()->whereIn('state', ['queued', 'sending', 'uncertain']),
            'przekazywanie dokumentu do Allegro' => AllegroInvoiceExport::query()->whereIn('state', ['pending', 'uploading']),
            'przekazywanie numeru przesyłki do Allegro' => AllegroShipmentExport::query()->where('state', 'pending'),
            'przekazywanie statusu do Von Halsky' => VonHalskyOrderCommand::query()->whereIn('state', ['queued', 'sending', 'pending', 'uncertain']),
        ];

        foreach ($operations as $label => $query) {
            $orderId = $query->whereIn('order_id', $orderIds)->lockForUpdate()->first(['order_id'])?->order_id;
            if ($orderId !== null) {
                $this->pendingOperation((int) $orderId, $label);
            }
        }

        $submission = KsefInvoiceSubmission::query()->whereIntegerInRaw('invoice_id', array_keys($invoiceOrders))
            ->whereIn('status', array_map(
                fn (KsefInvoiceSubmissionStatus $status): string => $status->value,
                array_filter(KsefInvoiceSubmissionStatus::cases(), fn (KsefInvoiceSubmissionStatus $status): bool => ! $status->isTerminal()),
            ))
            ->lockForUpdate()->first(['invoice_id']);
        if ($submission) {
            $this->pendingOperation((int) $invoiceOrders[$submission->invoice_id], 'wysyłka do KSeF');
        }
    }

    private function pendingOperation(int $orderId, string $label): never
    {
        throw ValidationException::withMessages([
            'order_ids' => 'Zamówienie nr '.$orderId.' ma operację w toku lub wymagającą sprawdzenia ('.$label.'). Niczego nie usunięto.',
        ]);
    }
}
