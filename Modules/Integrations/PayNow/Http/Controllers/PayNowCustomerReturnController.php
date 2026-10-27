<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Services\PortalPaymentService;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowCustomerPaymentService;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;
use Symfony\Component\HttpFoundation\Response;

class PayNowCustomerReturnController extends Controller
{
    public function __construct(
        private readonly PayNowReturnUrlService $urls,
        private readonly PayNowCustomerPaymentService $payments,
    ) {}

    public function show(Request $request): Response
    {
        if (! $request->query->has('ref')) {
            return $this->page($request, null, $this->unavailable('Brak danych płatności.'), 200);
        }

        $payment = $this->urls->resolve($request);
        if ($payment && ($portal = app(PortalPaymentService::class)->returnUrl($request, $payment))) {
            return $this->secure(redirect()->to($portal, 303));
        }

        return $payment
            ? $this->page($request, $payment, $this->payments->present($payment))
            : $this->page($request, null, $this->unavailable('Link jest nieprawidłowy lub wygasł.'), 403);
    }

    public function status(Request $request): Response
    {
        $payment = $this->urls->resolve($request);
        if (! $payment) {
            return $this->page($request, null, $this->unavailable('Link jest nieprawidłowy lub wygasł.'), 403);
        }

        $data = $this->payments->check($payment, $request->query('paymentId'));

        return $request->expectsJson()
            ? $this->secure(response()->json($data))
            : $this->page($request, $payment, $data);
    }

    public function retry(Request $request): Response
    {
        $payment = $this->urls->resolve($request);
        if (! $payment) {
            return $this->page($request, null, $this->unavailable('Link jest nieprawidłowy lub wygasł.'), 403);
        }

        try {
            return $this->secure(redirect()->away($this->payments->retry($payment, $request->query('paymentId')), 303));
        } catch (ValidationException|PayNowApiException) {
            return $this->secure(redirect()->to($this->urls->withProviderHint($this->urls->forPayment($payment, request: $request), $request), 303)
                ->with('paynow_customer_error', 'Nie można teraz ponowić płatności. Sprawdź jej status ponownie lub skontaktuj się ze sprzedawcą.'));
        }
    }

    private function page(Request $request, ?PayNowPayment $payment, array $data, int $status = 200): Response
    {
        $nonce = Str::random(32);
        $canCheck = $payment !== null && ! in_array($data['state'], ['confirmed', 'settled'], true);
        $response = response()->view('paynow.return', [
            'data' => $data,
            'nonce' => $nonce,
            'portalUrl' => $payment ? app(PortalPaymentService::class)->returnUrl($request, $payment) : null,
            'statusUrl' => $canCheck ? $this->urls->withProviderHint($this->urls->forPayment($payment, 'paynow.return.status', $request), $request) : null,
            'retryUrl' => $canCheck ? $this->urls->withProviderHint($this->urls->forPayment($payment, 'paynow.return.retry', $request), $request) : null,
        ], $status);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; img-src 'self' data:; form-action 'self' https://paynow.pl https://*.paynow.pl; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");

        return $this->secure($response);
    }

    private function unavailable(string $message): array
    {
        return [
            'state' => 'unavailable', 'title' => 'Status płatności', 'message' => $message,
            'amount' => null, 'currency' => null, 'transactionId' => null,
            'canRetry' => false, 'poll' => false, 'isTest' => false,
        ];
    }

    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
