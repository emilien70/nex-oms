<?php

namespace Modules\Integrations\PayNow\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Models\PayNowShortLink;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PayNowShortLinkService
{
    public function __construct(
        private readonly PayNowReturnUrlService $returns,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function isAvailable(): bool
    {
        return Schema::hasTable('paynow_short_links');
    }

    public function forPayment(PayNowPayment $payment): string
    {
        return DB::transaction(function () use ($payment): string {
            $payment = PayNowPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $account = $payment->account;
            if (! $account || ! $payment->order_id) {
                $this->unavailable();
            }

            $link = PayNowShortLink::query()->where('paynow_payment_id', $payment->id)->lockForUpdate()->first();
            if (! $link) {
                $token = strtr(base64_encode(random_bytes(24)), '+/', '-_');
                $tokenHash = hash('sha256', $token);
                $targetHash = $this->targetHash($payment);
                $link = PayNowShortLink::query()->create([
                    'paynow_payment_id' => $payment->id,
                    'token_hash' => $tokenHash,
                    'token' => $token,
                    'identity_hash' => $this->identityHash($payment, $account, $tokenHash, $targetHash),
                    'target_hash' => $targetHash,
                    'expires_at' => $this->returns->expiresAt($payment),
                ]);
            }

            if ($link->revoked_at || $link->expires_at->isPast()
                || ! $link->expires_at->equalTo($this->returns->expiresAt($payment))
                || ! hash_equals($link->identity_hash, $this->identityHash($payment, $account, $link->token_hash, $link->target_hash))
                || ! hash_equals($link->token_hash, hash('sha256', $link->token))) {
                $this->unavailable();
            }

            return route('paynow.short-link', ['token' => $link->token]);
        }, 3);
    }

    public function destination(string $token): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{32}$/D', $token) !== 1 || ! $this->isAvailable()) {
            return null;
        }

        return DB::transaction(function () use ($token): ?string {
            $link = PayNowShortLink::query()->where('token_hash', hash('sha256', $token))->first();
            if (! $link || $link->revoked_at || ! $link->expires_at->isFuture()) {
                return null;
            }

            $payment = $link->payment;
            if (! $payment) {
                return null;
            }
            // Match the account -> order -> payment lock order of payment preparation.
            $account = PayNowAccount::query()->whereKey($payment->paynow_account_id)->lockForUpdate()->first();
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $payment = PayNowPayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $link = PayNowShortLink::query()->whereKey($link->id)->lockForUpdate()->first();
            if (! $payment || ! $account || ! $order || ! $link || $link->revoked_at
                || ! $link->expires_at->isFuture() || ! $account->is_active || ! $account->hasCompleteCredentials()
                || $link->paynow_payment_id !== $payment->id
                || ! $link->expires_at->equalTo($this->returns->expiresAt($payment))
                || ! hash_equals($link->token_hash, hash('sha256', $token))
                || ! hash_equals($link->identity_hash, $this->identityHash($payment, $account, $link->token_hash, $link->target_hash))) {
                return null;
            }

            if ($order->status === Order::STATUS_CANCELLED || ! $order->purchaseIsComplete()) {
                return null;
            }

            if ($payment->needs_review || $payment->status === PayNowPaymentStatus::Confirmed
                || $this->decimal->compare($order->paid_amount, $order->total_gross) >= 0
                || ! in_array($payment->status, [PayNowPaymentStatus::Created, PayNowPaymentStatus::Pending], true)
                || ! $payment->valid_until?->isFuture()
                || $order->currency !== $payment->currency
                || $order->customer_email !== $payment->buyer_email_snapshot
                || $this->decimal->compare($this->decimal->subtract($order->total_gross, $order->paid_amount), $payment->amount) !== 0
                || $this->hasConflictingAttempt($payment)) {
                return $this->returns->forPayment($payment);
            }

            if (! $payment->provider_payment_id || ! hash_equals($link->target_hash, $this->targetHash($payment))
                || ! $this->isProviderTarget((string) $payment->payment_url, $account->environment)) {
                return null;
            }

            return $payment->payment_url;
        }, 3);
    }

    private function hasConflictingAttempt(PayNowPayment $payment): bool
    {
        return PayNowPayment::query()->where('order_id', $payment->order_id)->whereKeyNot($payment->id)
            ->where(fn ($query) => $query->where('id', '>', $payment->id)
                ->orWhere('needs_review', true)
                ->orWhereIn('status', [PayNowPaymentStatus::Created->value, PayNowPaymentStatus::Pending->value, PayNowPaymentStatus::Abandoned->value])
                ->orWhere(fn ($query) => $query->where('status', PayNowPaymentStatus::Confirmed->value)->whereNull('accounted_at')))
            ->exists();
    }

    private function identityHash(PayNowPayment $payment, PayNowAccount $account, string $tokenHash, string $targetHash): string
    {
        return hash_hmac('sha256', json_encode([
            $payment->id, $payment->order_id, $payment->paynow_account_id, $payment->external_id,
            $payment->amount, $payment->currency, $payment->buyer_email_snapshot, $payment->description,
            $payment->valid_until?->toIso8601String(), $account->environment->value,
            $account->api_key, $account->signature_key,
            $this->returns->expiresAt($payment)->toIso8601String(), $tokenHash, $targetHash,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function targetHash(PayNowPayment $payment): string
    {
        return hash('sha256', json_encode([$payment->provider_payment_id, $payment->payment_url], JSON_THROW_ON_ERROR));
    }

    private function isProviderTarget(string $url, PayNowEnvironment $environment): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $sandbox = $host === 'sandbox.paynow.pl' || str_ends_with($host, '.sandbox.paynow.pl');

        return ($parts['scheme'] ?? null) === 'https'
            && ($host === 'paynow.pl' || str_ends_with($host, '.paynow.pl'))
            && ($environment === PayNowEnvironment::Sandbox ? $sandbox : ! $sandbox)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['payment_link' => 'Nie mozna udostepnic linku tej platnosci. Sprawdz jej stan w OMS.']);
    }
}
