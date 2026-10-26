<?php

namespace Modules\Integrations\PayNow\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\Integrations\PayNow\Models\PayNowPayment;

class PayNowReturnUrlService
{
    public function forPayment(PayNowPayment $payment, string $route = 'paynow.return'): string
    {
        return URL::temporarySignedRoute($route, $this->expiresAt($payment), ['ref' => $payment->external_id]);
    }

    public function expiresAt(PayNowPayment $payment): CarbonImmutable
    {
        return ($payment->valid_until ?? $payment->created_at->toImmutable()->addDays(91))->addDay();
    }

    public function resolve(Request $request): ?PayNowPayment
    {
        foreach (['ref' => 100, 'paymentId' => 100, 'paymentStatus' => 30] as $key => $limit) {
            $value = $request->query($key);
            if ($value !== null && (! is_string($value) || strlen($value) > $limit)) {
                return null;
            }
        }

        // PayNow appends these hints; neither is trusted as proof of payment.
        if (! URL::hasValidSignature($request, true, ['paymentId', 'paymentStatus'])) {
            return null;
        }

        return PayNowPayment::query()->where('external_id', $request->query('ref'))->first();
    }

    public function withProviderHint(string $url, Request $request): string
    {
        $paymentId = $request->query('paymentId');

        return is_string($paymentId) && $paymentId !== ''
            ? $url.'&'.http_build_query(['paymentId' => $paymentId])
            : $url;
    }
}
