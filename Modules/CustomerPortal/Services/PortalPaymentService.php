<?php

namespace Modules\CustomerPortal\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowCustomerPaymentService;
use Modules\Integrations\PayNow\Services\PayNowShortLinkService;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PortalPaymentService
{
    private const SESSION_KEY = 'customer_portal_payments';

    private const FAILED = [PayNowPaymentStatus::Rejected, PayNowPaymentStatus::Error, PayNowPaymentStatus::Expired];

    public function __construct(
        private readonly PortalAccessService $access,
        private readonly PayNowCustomerPaymentService $customerPayments,
        private readonly PayNowShortLinkService $shortLinks,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly PortalUrlService $urls,
    ) {}

    public function ready(): bool
    {
        return $this->access->ready() && Schema::hasColumns('customer_portal_settings', [
            'paynow_enabled', 'paynow_retry_enabled', 'paynow_sources',
        ]) && $this->shortLinks->isAvailable();
    }

    public function presentation(Order $order, PortalSetting $settings, ?string $token, bool $preview): array
    {
        $data = ['actions' => [], 'transactionId' => null, 'message' => null, 'isTest' => false];
        if (! $this->ready() || ! $settings->paynow_enabled || ! $this->sourceAllowed($order, $settings)) {
            return $data;
        }
        $payments = PayNowPayment::query()->where('order_id', $order->id)->latest('id')->get();
        $confirmed = $payments->first(fn ($payment) => $payment->status === PayNowPaymentStatus::Confirmed && $payment->accounted_at && ! $payment->needs_review);
        $data['transactionId'] = $confirmed?->provider_payment_id;
        if ($payments->contains(fn ($payment) => $payment->needs_review || ($payment->status === PayNowPaymentStatus::Confirmed && ! $payment->accounted_at))) {
            $data['message'] = 'Trwa wyjaśnianie płatności. Nie zlecaj kolejnej wpłaty; w razie wątpliwości skontaktuj się ze sprzedawcą.';
        }
        if ($preview || ! $token) {
            return $data;
        }
        try {
            $context = $this->context($token);
            $payment = $context['payment'];
            $data['isTest'] = $context['account']->environment === PayNowEnvironment::Sandbox;
            foreach (['pay' => $payment->status === PayNowPaymentStatus::Pending ? 'Kontynuuj płatność' : 'Zapłać', 'status' => 'Sprawdź status płatności', 'retry' => 'Ponów płatność'] as $action => $label) {
                if ($this->allowed($context, $action)) {
                    $data['actions'][] = ['url' => $this->urls->route('customer-portal.payment', ['orderNumber' => $order->id, 'token' => $token, 'action' => $action]),
                        'intent' => $this->intent($context, $action), 'label' => $label, 'primary' => $action !== 'status'];
                }
            }
            if (in_array($payment->status, self::FAILED, true)) {
                $data['message'] = 'Płatność nie została zrealizowana.';
            } elseif (! $payment->valid_until?->isFuture()) {
                $data['message'] = 'Link płatności wymaga sprawdzenia. Sprawdź status lub skontaktuj się ze sprzedawcą.';
            }
        } catch (ValidationException) {
            // Missing or unsafe attempts never become a first-payment creation path.
        }

        return $data;
    }

    /** @return array{payment: PayNowPayment, url: ?string, grant: array} */
    public function perform(string $token, string $intent, string $action, ?int $orderNumber = null): array
    {
        $lock = Cache::lock('portal-paynow-'.hash('sha256', $token), 90);
        if (! $lock->get()) {
            $this->deny();
        }
        try {
            $context = DB::transaction(function () use ($token, $intent, $action, $orderNumber): array {
                $context = $this->context($token, true, orderNumber: $orderNumber);
                if (! $this->allowed($context, $action) || ! hash_equals($this->intent($context, $action), $intent)) {
                    $this->deny();
                }

                return $context;
            }, 3);
            $fingerprint = $this->fingerprint($context);
            $prepared = null;
            $authorize = function (?PayNowPayment $candidate = null) use ($token, $context, $fingerprint, $orderNumber, &$prepared): void {
                $fresh = $this->context($token, true, $candidate, $context['payment'], $orderNumber);
                if (! hash_equals($fingerprint, $this->fingerprint($fresh))) {
                    $this->deny();
                }
                if ($candidate) {
                    $prepared = $candidate;
                }
            };
            $payment = $context['payment'];
            if ($action === 'status') {
                $status = $this->customerPayments->check($payment, null, $authorize);
                if ($status['state'] === 'unavailable') {
                    $this->deny();
                }

                return ['payment' => $payment->refresh(), 'url' => null, 'grant' => $context['grant']];
            }
            if ($action === 'retry') {
                $this->customerPayments->retry($payment, null, $authorize);
                if (! $prepared) {
                    $this->deny();
                }
                $payment = $prepared->refresh();
            }

            return DB::transaction(function () use ($token, $context, $fingerprint, $payment, $orderNumber): array {
                $fresh = $this->context($token, true, $payment, $context['payment'], $orderNumber);
                if (! hash_equals($fingerprint, $this->fingerprint($fresh))) {
                    $this->deny();
                }
                $url = $this->shortLinks->forPayment($payment);
                $code = basename((string) parse_url($url, PHP_URL_PATH));
                $destination = $this->shortLinks->destination($code);
                if ($destination !== $payment->payment_url) {
                    $this->deny();
                }

                return ['payment' => $payment, 'url' => $url, 'grant' => $fresh['grant']];
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function rememberReturn(Request $request, array $result): void
    {
        $payment = $result['payment'];
        $link = $result['grant']['link'];
        // This browser already holds the portal capability; a payment URL alone must not grant it.
        $contexts = $request->session()->get(self::SESSION_KEY, []);
        $contexts[$payment->external_id] = ['link_id' => $link->id, 'generation' => $link->generation,
            'token_hash' => $link->token_hash, 'payment_id' => $payment->id,
            'fingerprint' => $this->returnFingerprint($payment), 'expires' => min($link->expires_at->getTimestamp(), now()->addDay()->getTimestamp())];
        $request->session()->put(self::SESSION_KEY, array_slice($contexts, -10, null, true));
    }

    public function returnUrl(Request $request, PayNowPayment $payment): ?string
    {
        $saved = $request->session()->get(self::SESSION_KEY, [])[$payment->external_id] ?? null;
        if (! is_array($saved) || ! $this->access->ready() || ($saved['expires'] ?? 0) <= now()->getTimestamp()
            || ($saved['payment_id'] ?? null) !== $payment->id || ! $payment->account
            || ! hash_equals((string) ($saved['fingerprint'] ?? ''), $this->returnFingerprint($payment))) {
            return null;
        }
        $link = PortalLink::query()->find($saved['link_id'] ?? null);
        if (! $link || $link->generation !== ($saved['generation'] ?? null)
            || ! hash_equals($link->token_hash, (string) ($saved['token_hash'] ?? '')) || $link->order_id !== $payment->order_id) {
            return null;
        }

        return $this->access->resolve($link->token, orderNumber: $payment->order_id) ? $this->urls->link($link) : null;
    }

    private function context(string $token, bool $lock = false, ?PayNowPayment $prepared = null, ?PayNowPayment $source = null, ?int $orderNumber = null): array
    {
        if (! $this->ready()) {
            $this->deny();
        }
        // Consistent lock order: portal settings -> PayNow account -> order -> link -> attempts.
        $settings = PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $account = PayNowAccount::query()->where('account_key', PayNowAccount::DEFAULT_ACCOUNT_KEY)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $grant = $this->access->resolve($token, $lock, $orderNumber);
        $order = $grant['order'] ?? null;
        if (! $grant || ! $settings?->paynow_enabled || ! $account?->is_active || ! $account->hasCompleteCredentials()
            || $account->last_test_status !== PayNowConnectionTestStatus::Success || ! $this->sourceAllowed($order, $settings)
            || ! $order->purchaseIsComplete() || $order->cash_on_delivery || $order->status === Order::STATUS_CANCELLED
            || $order->currency !== 'PLN') {
            $this->deny();
        }
        $remaining = $this->decimal->subtract($order->total_gross, $order->paid_amount);
        if ($this->decimal->compare($remaining, '1.00') < 0 || $this->decimal->compare($remaining, '1000000.00') > 0) {
            $this->deny();
        }
        $payments = PayNowPayment::query()->where('order_id', $order->id)->latest('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $latest = $payments->first();
        $payment = $source ? $payments->firstWhere('id', $source->id) : $latest;
        $candidate = $prepared ? $payments->firstWhere('id', $prepared->id) : $payment;
        if (! $payment || ! $candidate || ! $latest?->is($candidate)
            || $payment->paynow_account_id !== $account->id || ! $payment->provider_payment_id || ! $payment->payment_url
            || $payment->currency !== $order->currency || $payment->buyer_email_snapshot !== $order->customer_email
            || $this->decimal->compare($remaining, $payment->amount) !== 0
            || $payments->contains(fn ($p) => $p->needs_review || ($p->status === PayNowPaymentStatus::Confirmed && ! $p->accounted_at)
                || (! $p->is($latest) && in_array($p->status, [PayNowPaymentStatus::Created, PayNowPaymentStatus::Pending, PayNowPaymentStatus::Abandoned], true)))) {
            $this->deny();
        }
        if (! $candidate->is($payment) && (! $settings->paynow_retry_enabled || ! in_array($payment->status, self::FAILED, true)
            || $candidate->paynow_account_id !== $account->id || $candidate->buyer_email_snapshot !== $order->customer_email
            || $candidate->currency !== $order->currency || $this->decimal->compare($remaining, $candidate->amount) !== 0
            || ! $candidate->valid_until?->isFuture()
            || ! in_array($candidate->status, [PayNowPaymentStatus::Created, PayNowPaymentStatus::Pending], true))) {
            $this->deny();
        }

        return compact('grant', 'order', 'settings', 'account', 'payment');
    }

    private function sourceAllowed(Order $order, PortalSetting $settings): bool
    {
        return in_array($order->source, ['manual', 'prestashop'], true) && in_array($order->source, $settings->paynow_sources ?? ['manual'], true);
    }

    private function allowed(array $context, string $action): bool
    {
        $payment = $context['payment'];

        return match ($action) {
            'pay' => in_array($payment->status, [PayNowPaymentStatus::Created, PayNowPaymentStatus::Pending], true) && $payment->valid_until?->isFuture(),
            'status' => $payment->status !== PayNowPaymentStatus::Confirmed,
            'retry' => $context['settings']->paynow_retry_enabled && in_array($payment->status, self::FAILED, true),
            default => false,
        };
    }

    private function intent(array $context, string $action): string
    {
        return $this->hash([$this->fingerprint($context), $action, $context['payment']->status->value]);
    }

    private function fingerprint(array $context): string
    {
        $link = $context['grant']['link'];
        $order = $context['order'];

        return $this->hash([$context['settings']->revision, $context['settings']->paynow_enabled,
            $context['settings']->paynow_retry_enabled, $context['settings']->paynow_sources,
            $link->token_hash, $link->generation, $link->identity_hash, $link->expires_at->toISOString(),
            $order->id, $order->source, $order->status, $order->currency, $order->total_gross, $order->paid_amount,
            $order->customer_email, $order->cash_on_delivery, $this->returnFingerprint($context['payment'], $context['account'])]);
    }

    private function returnFingerprint(PayNowPayment $payment, ?PayNowAccount $account = null): string
    {
        $account ??= $payment->account;

        return $this->hash([$payment->id, $payment->order_id, $payment->external_id, $payment->paynow_account_id,
            $payment->provider_payment_id, $payment->amount, $payment->currency, $payment->buyer_email_snapshot,
            $payment->description, $payment->payment_url, $payment->valid_until?->toISOString(),
            $account?->environment->value, $account?->api_key, $account?->signature_key, $account?->is_active,
            $account?->description_template, $account?->continue_url, $account?->link_validity_seconds]);
    }

    private function hash(array $values): string
    {
        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['portal_payment' => 'Płatność nie jest teraz dostępna. Odśwież stronę i sprawdź jej status lub skontaktuj się ze sprzedawcą.']);
    }
}
