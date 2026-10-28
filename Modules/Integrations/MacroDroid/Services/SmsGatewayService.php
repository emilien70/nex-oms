<?php

namespace Modules\Integrations\MacroDroid\Services;

use App\Exceptions\OrderEmailException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\Integrations\MacroDroid\Jobs\NotifySmsDeviceJob;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;

class SmsGatewayService
{
    public function __construct(private readonly SmsPreparationService $preparation) {}

    public function claimNext(SmsDevice $authenticated): array
    {
        return DB::transaction(function () use ($authenticated): array {
            PortalSetting::query()->lockForUpdate()->first();
            $device = SmsDevice::query()->whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->enabled && $device->generation === $authenticated->generation
                && hash_equals((string) $device->token_hash, (string) $authenticated->token_hash), 401);
            $next = SmsMessage::query()->where('device_id', $device->id)->where('status', 'queued')
                ->where('expires_at', '>', now())->orderBy('id')->first();

            return $next ? $this->claim($authenticated, $next->public_id) : ['available' => false];
        });
    }

    public function claim(SmsDevice $authenticated, string $publicId): array
    {
        return $this->locked($authenticated, $publicId, function (SmsDevice $device, SmsMessage $message, ?Order $order): array {
            if ($message->status !== 'queued') {
                return ['available' => false];
            }
            if (! $this->current($device, $message, $order)) {
                return ['available' => false];
            }
            $base = SmsMessage::query()->where('device_id', $device->id)->whereNotNull('claimed_at');
            if ((clone $base)->where('claimed_at', '>', now()->subMinute())->count() >= $device->per_minute
                || (clone $base)->where('claimed_at', '>', now()->subDay())->count() >= $device->per_day
                || SmsMessage::query()->where('device_id', $device->id)->whereIn('status', ['claimed', 'submitting'])->exists()) {
                return ['available' => false, 'retry_after' => 60];
            }
            $token = Str::random(64);
            $message->update(['status' => 'claimed', 'claim_hash' => hash('sha256', $token),
                'claimed_at' => now(), 'claim_expires_at' => now()->addSeconds(120)->min($message->expires_at)]);

            return ['available' => true, 'job_id' => $message->public_id, 'claim_token' => $token,
                'phone' => $message->recipient, 'text' => $message->body,
                'expires_at' => $message->claim_expires_at->toIso8601String()];
        });
    }

    public function begin(SmsDevice $authenticated, string $publicId, string $claim): array
    {
        return $this->locked($authenticated, $publicId, function (SmsDevice $device, SmsMessage $message, ?Order $order) use ($claim): array {
            $this->assertClaim($message, $claim);
            if ($message->status !== 'claimed' || ! $this->current($device, $message, $order)) {
                return ['may_send' => false];
            }
            if (! $message->claim_expires_at?->isFuture()) {
                $message->update(['status' => 'expired', 'completed_at' => now()]);

                return ['may_send' => false];
            }
            // A lost response must never authorize a second attempt at the SIM side effect.
            $message->update(['status' => 'submitting', 'sending_at' => now()]);

            return ['may_send' => true];
        });
    }

    public function acknowledge(SmsDevice $authenticated, string $publicId, string $claim, string $outcome): array
    {
        return $this->locked($authenticated, $publicId, function (SmsDevice $device, SmsMessage $message) use ($claim, $outcome): array {
            $this->assertClaim($message, $claim);
            if (in_array($message->status, ['claimed', 'submitting'], true)) {
                $status = $message->status === 'claimed' ? 'failed' : ($outcome === 'submitted' ? 'submitted' : 'uncertain');
                if ($message->status === 'claimed' && $outcome !== 'failed') {
                    abort(409);
                }
                $message->update(['status' => $status, 'completed_at' => now(),
                    'safe_error' => $status === 'failed' ? 'phone_rejected_before_send' : null]);
                $this->wakeNext($device);
            }

            return ['status' => $message->status];
        });
    }

    public function expire(): int
    {
        $count = 0;
        foreach (SmsDevice::query()->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id): int {
                SmsDevice::query()->whereKey($id)->lockForUpdate()->first();
                $expired = SmsMessage::query()->where('device_id', $id)->whereIn('status', ['queued', 'claimed'])
                    ->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere('claim_expires_at', '<=', now()))
                    ->update(['status' => 'expired', 'completed_at' => now()]);

                return $expired + SmsMessage::query()->where('device_id', $id)->where('status', 'submitting')
                    ->where('sending_at', '<=', now()->subMinutes(5))->update(['status' => 'uncertain', 'completed_at' => now()]);
            });
        }

        foreach (SmsDevice::query()->where('enabled', true)->get() as $device) {
            $this->wakeNext($device);
        }

        return $count;
    }

    private function wakeNext(SmsDevice $device): void
    {
        if (! $device->enabled || SmsMessage::query()->where('device_id', $device->id)->whereIn('status', ['claimed', 'submitting'])->exists()) {
            return;
        }
        $next = SmsMessage::query()->where('device_id', $device->id)->where('status', 'queued')
            ->where('expires_at', '>', now())->orderBy('id')->first();
        if ($next) {
            NotifySmsDeviceJob::dispatch($next->public_id, $device->generation)->afterCommit();
        }
    }

    public function cancel(SmsMessage $candidate): void
    {
        DB::transaction(function () use ($candidate): void {
            SmsDevice::query()->whereKey($candidate->device_id)->lockForUpdate()->first();
            $message = SmsMessage::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($message->status, ['queued', 'claimed', 'cancelled'], true), 409, 'Wysylka zostala juz rozpoczeta.');
            if ($message->status !== 'cancelled') {
                $message->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
        });
    }

    private function locked(SmsDevice $authenticated, string $publicId, callable $operation): array
    {
        return DB::transaction(function () use ($authenticated, $publicId, $operation): array {
            PortalSetting::query()->lockForUpdate()->first();
            $device = SmsDevice::query()->whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->enabled && $device->generation === $authenticated->generation
                && hash_equals((string) $device->token_hash, (string) $authenticated->token_hash), 401);
            $candidate = SmsMessage::query()->where('public_id', $publicId)->where('device_id', $device->id)->firstOrFail();
            $order = $candidate->order_id ? Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first() : null;
            $message = SmsMessage::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $device->update(['last_seen_at' => now()]);

            return $operation($device, $message, $order);
        });
    }

    private function current(SmsDevice $device, SmsMessage $message, ?Order $order): bool
    {
        $status = null;
        if ($order) {
            try {
                $order->assertPurchaseReady();
            } catch (ValidationException) {
                $status = 'cancelled';
            }
        }
        if (! $message->expires_at->isFuture()) {
            $status = 'expired';
        } elseif ($message->device_generation !== $device->generation || ($message->source !== 'test' && ! $order)) {
            $status = 'cancelled';
        } elseif ($message->customer_recipient && $order) {
            try {
                if (! hash_equals((string) $message->context_hash, $this->preparation->context($order, $message->uses_portal))
                    || SmsPhoneNumber::normalize($order->customer_phone) !== $message->recipient) {
                    $status = 'cancelled';
                }
                if ($message->uses_portal) {
                    app(PortalAccessService::class)->templateUrl($order);
                }
            } catch (OrderEmailException|ValidationException $exception) {
                $status = 'cancelled';
            }
        }
        if ($status) {
            $message->update(['status' => $status, 'completed_at' => now(), 'safe_error' => 'sms_context_expired']);
        }

        return $status === null;
    }

    private function assertClaim(SmsMessage $message, string $claim): void
    {
        abort_unless($message->claim_hash && hash_equals($message->claim_hash, hash('sha256', $claim)), 403);
    }
}
