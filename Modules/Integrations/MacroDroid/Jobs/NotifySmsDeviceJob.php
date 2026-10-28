<?php

namespace Modules\Integrations\MacroDroid\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;

class NotifySmsDeviceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public readonly string $publicId, public readonly int $generation, public readonly bool $probe = false)
    {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public static function validWebhook(string $url): bool
    {
        return preg_match('~^https://trigger\.macrodroid\.com/[A-Za-z0-9-]{8,128}/[A-Za-z0-9_-]{1,80}$~D', $url) === 1;
    }

    public function handle(): void
    {
        $device = SmsDevice::configured();
        if (! $device?->enabled || $device->generation !== $this->generation || ! self::validWebhook((string) $device->webhook_url)) {
            return;
        }
        if ($this->probe) {
            if ($device->probe_id !== $this->publicId || ! $device->probe_expires_at?->isFuture()) {
                return;
            }
        } else {
            $message = SmsMessage::query()->where('public_id', $this->publicId)->first();
            if (! $message || $message->device_id !== $device->id || $message->status !== 'queued' || ! $message->expires_at->isFuture()) {
                return;
            }
            $first = SmsMessage::query()->where('device_id', $device->id)->where('status', 'queued')
                ->where('expires_at', '>', now())->orderBy('id')->value('public_id');
            if ($first !== $message->public_id || SmsMessage::query()->where('device_id', $device->id)->whereIn('status', ['claimed', 'submitting'])->exists()) {
                return;
            }
        }
        $rateKey = 'sms-wakeup:'.$device->id.':'.$device->generation.':'.($this->probe ? 'probe' : 'sms');
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return;
        }
        RateLimiter::hit($rateKey, 60);
        // The cloud receives only a wake-up, without even a job identifier or SMS data.
        try {
            $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(10)
                ->get($device->webhook_url.($this->probe ? '-probe' : ''));
        } catch (\Throwable) {
            throw new \RuntimeException('sms_device_wakeup_failed');
        }
        if (! $response->successful()) {
            throw new \RuntimeException('sms_device_wakeup_failed');
        }
    }
}
