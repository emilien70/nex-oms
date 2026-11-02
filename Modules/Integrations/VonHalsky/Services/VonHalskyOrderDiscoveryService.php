<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Throwable;

class VonHalskyOrderDiscoveryService
{
    public function __construct(private readonly VonHalskyClient $client,
        private readonly VonHalskyOrderImportState $state,
        private readonly VonHalskyOrderImportDispatcher $dispatcher,
        private readonly VonHalskyOrderImportService $importer) {}

    public function discover(int $accountId, int $revision, bool $manual = false): void
    {
        $account = $this->state->account($accountId, $revision, $manual);
        if (! $account) {
            return;
        }
        $cutoff = CarbonImmutable::now()->subMinute()->startOfSecond();
        $reconcile = ! $account->last_order_reconciliation_at || $account->last_order_reconciliation_at->lte(now()->subDay());
        $from = $reconcile || ! $account->order_import_cursor_at ? $account->order_import_started_at
            : $account->order_import_cursor_at->subMinutes(5)->max($account->order_import_started_at);
        if ($cutoff->lt($from)) {
            return;
        }
        $offset = 0;
        $previousDate = null;
        $finished = false;
        $deadline = microtime(true) + 45;
        for ($page = 0; $page < 50; $page++) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $response = $this->client->ordersUpdatedSince($account, $from, $offset);
            if (! $this->state->account($accountId, $revision, $manual)) {
                return;
            }
            $rows = $response['data'];
            $total = $response['page']['total'];
            foreach ($rows as $row) {
                [$created, $updated] = $this->validateRow($account, $row);
                if ($updated->lt($from) || ($previousDate && $updated->lt($previousDate))) {
                    throw new VonHalskyApiException('Lista Von Halsky nie zachowuje zakresu lub kolejnosci zmian. Kursor nie zostal przesuniety.');
                }
                $previousDate = $updated;
                if ($updated->gt($cutoff)) {
                    $finished = true;
                    break;
                }
                if ($created->gte($account->order_import_started_at)) {
                    $this->remember($accountId, $revision, $manual, $row, $created, $updated);
                }
            }
            if ($finished || $offset + count($rows) >= $total) {
                $finished = true;
                break;
            }
            if (count($rows) !== 30) {
                throw new VonHalskyApiException('Niekompletna strona Von Halsky. Kursor nie zostal przesuniety.');
            }
            $offset += count($rows);
        }
        if (! $finished) {
            throw new VonHalskyApiException('Przekroczono bezpieczny zakres jednego odczytu Von Halsky. Kursor zachowano; sprawdz zakres pobierania.');
        }
        DB::transaction(function () use ($accountId, $revision, $manual, $cutoff, $reconcile): void {
            $current = $this->state->account($accountId, $revision, $manual, lock: true);
            if (! $current) {
                return;
            }
            $current->forceFill(['last_order_scan_at' => now(), 'last_order_import_error' => null]);
            if (! $current->order_import_cursor_at || $current->order_import_cursor_at->lt($cutoff)) {
                $current->order_import_cursor_at = $cutoff;
            }
            if ($reconcile) {
                $current->last_order_reconciliation_at = now();
            }
            $current->save();
        });
        if ($current = $this->state->account($accountId, $revision, $manual)) {
            $this->dispatcher->dispatchPending($current, $manual);
        }
    }

    public function recordFailure(int $id, int $revision, ?Throwable $exception, bool $manual = false): void
    {
        VonHalskyAccount::query()->whereKey($id)->where('order_import_revision', $revision)->update([
            'last_order_import_error' => $exception instanceof VonHalskyApiException
                ? mb_substr($exception->getMessage(), 0, 1000) : 'Nie udalo sie zakonczyc odczytu Von Halsky. Proba zostanie ponowiona; kursor zachowano.',
        ]);
        if ($account = $this->state->account($id, $revision, $manual)) {
            $this->dispatcher->dispatchPending($account, $manual);
        }
    }

    private function validateRow(VonHalskyAccount $account, mixed $row): array
    {
        if (! is_array($row) || ! is_string($row['id'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $row['id'])
            || ! is_string($row['organizationId'] ?? null) || strtolower($row['organizationId']) !== strtolower($account->organization_id)
            || ! is_string($row['status'] ?? null) || strlen($row['status']) > 50) {
            throw new VonHalskyApiException('Nieprawidlowe dane lub organizacja na liscie Von Halsky.');
        }
        $dates = [];
        foreach ([$row['createdAt'] ?? null, $row['updatedAt'] ?? $row['createdAt'] ?? null] as $value) {
            if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $value)) {
                throw new VonHalskyApiException('Nieprawidlowa data zamowienia na liscie Von Halsky.');
            }
            try {
                $dates[] = CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
            } catch (Throwable) {
                throw new VonHalskyApiException('Nieprawidlowa data zamowienia na liscie Von Halsky.');
            }
        }
        if ($dates[1]->lt($dates[0])) {
            throw new VonHalskyApiException('Niespojna data zmiany zamowienia Von Halsky.');
        }

        return $dates;
    }

    private function remember(int $id, int $revision, bool $manual, array $row, CarbonImmutable $created, CarbonImmutable $updated): void
    {
        DB::transaction(function () use ($id, $revision, $manual, $row, $created, $updated): void {
            $account = $this->state->account($id, $revision, $manual, lock: true);
            if (! $account) {
                return;
            }
            $attempt = VonHalskyOrderImportAttempt::query()->firstOrCreate([
                'environment' => $account->environment->value, 'organization_id' => $account->organization_id, 'external_order_id' => $row['id'],
            ], ['von_halsky_account_id' => $id, 'external_created_at' => $created, 'external_updated_at' => $updated,
                'external_status' => $row['status'], 'discovered_at' => now(), 'last_seen_at' => now(), 'status' => 'pending']);
            $attempt = VonHalskyOrderImportAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->von_halsky_account_id !== null && $attempt->von_halsky_account_id !== $id) {
                throw new VonHalskyApiException('Zamowienie ma historie przypisana do innego konta Von Halsky.');
            }
            $link = $this->importer->link($account, $row['id']);
            $older = $updated->lt($attempt->external_updated_at);
            if ($older && ! $link) {
                return;
            }
            $changed = ! $attempt->external_updated_at->equalTo($updated) || $attempt->external_status !== $row['status'];
            $attempt->fill(['von_halsky_account_id' => $id, 'last_seen_at' => now()]);
            if (! $older) {
                $attempt->fill(['external_status' => $row['status'], 'external_updated_at' => $updated]);
            }
            if ($link) {
                $order = $link->order_id ? Order::withTrashed()->find($link->order_id) : null;
                $valid = $order && ! $order->trashed() && $order->source === 'von_halsky' && $order->external_id === $row['id'];
                $attempt->fill(['order_id' => $order?->id, 'status' => $valid ? 'imported' : 'deleted',
                    'processing_token' => null, 'processing_expires_at' => null, 'next_attempt_at' => null, 'last_message' => null]);
            } elseif ($changed && ! in_array($attempt->status, ['imported', 'deleted'], true)) {
                $attempt->fill(['status' => 'pending', 'processing_token' => null, 'processing_expires_at' => null,
                    'next_attempt_at' => null, 'last_message' => null]);
            }
            $attempt->save();
        });
    }
}
