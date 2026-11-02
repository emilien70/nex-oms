<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Throwable;

class VonHalskyOrderProcessingService
{
    public function __construct(private readonly VonHalskyOrderImportState $state,
        private readonly VonHalskyOrderPreviewService $previews,
        private readonly VonHalskyOrderImportService $importer) {}

    public function process(int $accountId, int $revision, int $attemptId, bool $manual = false): void
    {
        $claim = DB::transaction(function () use ($accountId, $revision, $attemptId, $manual): ?array {
            $account = $this->state->account($accountId, $revision, $manual, lock: true);
            $attempt = $account ? VonHalskyOrderImportAttempt::query()->lockForUpdate()->find($attemptId) : null;
            if (! $account || ! $attempt || ! $this->state->owns($account, $attempt)
                || in_array($attempt->status, ['imported', 'deleted', 'ignored', 'needs_quantity', 'blocked'], true)
                || ($attempt->status === 'processing' && $attempt->processing_expires_at?->isFuture())
                || $attempt->next_attempt_at?->isFuture()) {
                return null;
            }
            $attempt->forceFill(['status' => 'processing', 'processing_token' => (string) Str::uuid(),
                'processing_expires_at' => now()->addSeconds(180), 'last_attempt_at' => now(),
                'next_attempt_at' => null, 'attempts' => $attempt->attempts + 1])->save();

            return [$account, $attempt];
        });
        if (! $claim) {
            return;
        }
        [$account, $attempt] = $claim;
        try {
            if ($link = $this->importer->link($account, $attempt->external_order_id)) {
                $order = $link->order_id ? Order::withTrashed()->find($link->order_id) : null;
                $valid = $order && ! $order->trashed() && $order->source === 'von_halsky' && $order->external_id === $attempt->external_order_id;
                $this->finish($account, $attempt, $valid ? 'imported' : 'deleted', null, $order?->id);

                return;
            }
            $result = $this->previews->preview($account, $attempt->external_order_id);
            $preview = $result['import'];
            if (in_array($result['status'], ['CANCELED', 'CANCELLED', 'REFUSED', 'REJECTED'], true)) {
                $this->finish($account, $attempt, 'ignored', 'Zakup anulowany lub odrzucony w Von Halsky.', externalStatus: $result['status']);
            } elseif ($result['status'] !== 'ACCEPTED' || ! $result['payment_recorded']) {
                $this->finish($account, $attempt, 'waiting', 'Oczekiwanie na potwierdzenie i platnosc w Von Halsky.',
                    retrySeconds: 600, externalStatus: $result['status']);
            } elseif ($preview['blockers'] !== []) {
                $this->finish($account, $attempt, 'blocked', mb_substr(implode(' ', $preview['blockers']), 0, 1000), externalStatus: $result['status']);
            } elseif ($preview['requires_quantities']) {
                $this->finish($account, $attempt, 'needs_quantity', 'Von Halsky nie podaje ilosci. Otworz podglad i potwierdz ilosci.', externalStatus: $result['status']);
            } else {
                $order = $this->importer->importAutomatic($account, $attempt, $attempt->processing_token, $preview['source_hash'], $manual);
                $this->finish($account, $attempt, 'imported', null, $order->id, externalStatus: $result['status']);
            }
        } catch (Throwable $exception) {
            $retry = $exception instanceof VonHalskyApiException
                && ($exception->httpStatus === null || $exception->httpStatus === 429 || $exception->httpStatus >= 500);
            $delay = $retry ? max($exception->retryAfter ?? 0, min(3600, 60 * (2 ** min($attempt->attempts - 1, 6)))) : null;
            $this->finish($account, $attempt, 'failed', $exception instanceof VonHalskyApiException
                ? $exception->getMessage() : 'Nie udalo sie przetworzyc zamowienia. Sprawdz podglad i konfiguracje.', retrySeconds: $delay);
        }
    }

    private function finish(VonHalskyAccount $context, VonHalskyOrderImportAttempt $contextAttempt, string $status,
        ?string $message, ?int $orderId = null, ?int $retrySeconds = null, ?string $externalStatus = null): void
    {
        DB::transaction(function () use ($context, $contextAttempt, $status, $message, $orderId, $retrySeconds, $externalStatus): void {
            $account = VonHalskyAccount::query()->whereKey($context->id)->lockForUpdate()->first();
            if (! $account || (int) $account->order_import_revision !== (int) $context->order_import_revision) {
                return;
            }
            $attempt = VonHalskyOrderImportAttempt::query()->whereKey($contextAttempt->id)
                ->where('processing_token', $contextAttempt->processing_token)->lockForUpdate()->first();
            if (! $attempt || ! $this->state->owns($account, $attempt)) {
                return;
            }
            $attempt->fill(['status' => $status, 'last_message' => $message === null ? null : mb_substr($message, 0, 1000),
                'order_id' => $orderId, 'next_attempt_at' => $retrySeconds === null ? null : now()->addSeconds($retrySeconds),
                'processing_token' => null, 'processing_expires_at' => null]);
            if ($externalStatus !== null) {
                $attempt->external_status = $externalStatus;
            }
            $attempt->save();
        });
    }
}
