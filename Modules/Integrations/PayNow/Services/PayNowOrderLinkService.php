<?php

namespace Modules\Integrations\PayNow\Services;

use App\Models\Order;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowOrderLink;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Models\PayNowShortLink;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PayNowOrderLinkService
{
    public function __construct(
        private readonly PayNowShortLinkService $shortLinks,
        private readonly PortalUrlService $urls,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function isAvailable(): bool
    {
        return Schema::hasTable('paynow_order_links') && $this->shortLinks->isAvailable();
    }

    public function forPayment(PayNowPayment $payment): string
    {
        $code = DB::transaction(function () use ($payment): string {
            PayNowAccount::query()->lockForUpdate()->findOrFail($payment->paynow_account_id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $latest = PayNowPayment::query()->where('order_id', $order->id)->latest('id')->lockForUpdate()->first();
            if (! $this->canAccess($order) || ! $latest?->is($payment)
                || trim((string) $order->customer_email) !== $latest->buyer_email_snapshot) {
                throw ValidationException::withMessages(['payment_link' => 'Dane zamówienia zmieniły się. Odśwież kartę i spróbuj ponownie.']);
            }

            $shortLink = $this->shortLinks->recordForPayment($latest);
            $buyerHash = $this->buyerHash($order);
            $link = PayNowOrderLink::query()->where('order_id', $order->id)->lockForUpdate()->first()
                ?? new PayNowOrderLink(['order_id' => $order->id]);
            if (! $link->exists || ! hash_equals($link->buyer_hash, $buyerHash)) {
                do {
                    $token = strtr(base64_encode(random_bytes(24)), '+/', '-_');
                    $hash = hash('sha256', $token);
                } while (PayNowShortLink::query()->where('token_hash', $hash)->exists());
                $link->fill(['token' => $token, 'token_hash' => $hash, 'buyer_hash' => $buyerHash]);
            }
            if (! $this->intact($link)) {
                throw ValidationException::withMessages(['payment_link' => 'Zapisany link do płatności nie jest dostępny.']);
            }
            $link->paynow_short_link_id = $shortLink->id;
            if ($link->isDirty()) {
                $link->save();
            }

            return $this->checkoutCode($link);
        }, 3);

        return $this->urls->route('paynow.order-link', ['orderNumber' => $payment->order_id, 'code' => $code]);
    }

    public function resolveCheckout(int $orderNumber, string $code): ?array
    {
        return $this->resolveLink(
            ['order_id' => $orderNumber],
            fn (PayNowOrderLink $link): bool => $link->order_id === $orderNumber && hash_equals($this->checkoutCode($link), $code),
        );
    }

    /** A null result lets the historical route look up payment-specific links. */
    public function resolve(string $token): ?array
    {
        $hash = hash('sha256', $token);

        return $this->resolveLink(['token_hash' => $hash], fn (PayNowOrderLink $link): bool => hash_equals($link->token_hash, $hash));
    }

    private function resolveLink(array $lookup, callable $matches): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }
        $snapshot = PayNowOrderLink::query()->where($lookup)->first();
        if (! $snapshot) {
            return null;
        }
        if (! $this->intact($snapshot) || ! $matches($snapshot)) {
            return ['state' => 'unavailable'];
        }
        $snapshot->load('shortLink.payment');

        return DB::transaction(function () use ($snapshot, $matches): array {
            // Keep the same parent lock order as payment preparation and short-link validation.
            $paymentSnapshot = $snapshot->shortLink?->payment;
            $account = $paymentSnapshot ? PayNowAccount::query()->lockForUpdate()->find($paymentSnapshot->paynow_account_id) : null;
            $order = Order::query()->lockForUpdate()->find($snapshot->order_id);
            $link = PayNowOrderLink::query()->lockForUpdate()->find($snapshot->id);
            if (! $order || ! $link || ! $this->canAccess($order) || $link->order_id !== $order->id
                || $link->paynow_short_link_id !== $snapshot->paynow_short_link_id
                || ! $this->intact($link) || ! $matches($link)
                || ! hash_equals($link->buyer_hash, $this->buyerHash($order))) {
                return ['state' => 'unavailable'];
            }

            $remaining = $this->decimal->subtract($order->total_gross, $order->paid_amount);
            if ($this->decimal->compare($remaining, '0.00') <= 0) {
                return ['state' => 'settled'];
            }

            $payment = $paymentSnapshot ? PayNowPayment::query()->lockForUpdate()->find($paymentSnapshot->id) : null;
            if (! $account || ! $payment || $payment->order_id !== $order->id || $payment->paynow_account_id !== $account->id) {
                return ['state' => 'waiting'];
            }

            try {
                $destination = $this->shortLinks->activeDestination($snapshot->shortLink->token);
            } catch (DecryptException) {
                $destination = null;
            }
            if (! $destination) {
                return ['state' => $payment->status === PayNowPaymentStatus::Expired && ! $payment->needs_review ? 'expired' : 'waiting'];
            }

            return [
                'state' => 'redirect',
                'destination' => $destination,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'is_test' => $account->environment === PayNowEnvironment::Sandbox,
            ];
        }, 3);
    }

    private function canAccess(Order $order): bool
    {
        return ! $order->isArchived() && $order->status !== Order::STATUS_CANCELLED && $order->purchaseIsComplete();
    }

    private function intact(PayNowOrderLink $link): bool
    {
        try {
            return is_string($link->token) && preg_match('/^[A-Za-z0-9_-]{32}$/D', $link->token) === 1
                && hash_equals($link->token_hash, hash('sha256', $link->token));
        } catch (DecryptException) {
            return false;
        }
    }

    private function checkoutCode(PayNowOrderLink $link): string
    {
        // Derive a payment-only code without replacing any previously shared token.
        for ($block = 0; ; $block++) {
            $digest = hash_hmac('sha256', 'paynow-checkout-v1:'.$link->order_id.':'.$link->token.':'.$block, (string) config('app.key'), true);
            $characters = str_replace(['+', '/', '='], '', base64_encode($digest));
            if (strlen($characters) >= 10) {
                return substr($characters, 0, 10);
            }
        }
    }

    private function buyerHash(Order $order): string
    {
        return hash_hmac('sha256', json_encode([
            'paynow-order-link-v1', $order->id, $order->source, $order->external_id,
            mb_strtolower(trim((string) $order->customer_email)),
            $order->billing_name, $order->billing_company_name, $order->billing_tax_id,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
