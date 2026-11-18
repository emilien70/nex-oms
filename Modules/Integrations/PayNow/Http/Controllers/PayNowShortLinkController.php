<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Integrations\PayNow\Services\PayNowOrderLinkService;
use Modules\Integrations\PayNow\Services\PayNowShortLinkService;
use Symfony\Component\HttpFoundation\Response;

class PayNowShortLinkController extends Controller
{
    public function __invoke(Request $request, string $token, PayNowShortLinkService $links, PayNowOrderLinkService $orders): Response
    {
        $checkout = $orders->resolve($token);
        if ($checkout === null) {
            $target = $links->destination($token);
            $response = $target !== null ? redirect()->away($target, 302) : $this->page(['state' => 'unavailable']);
        } else {
            $response = $this->checkoutResponse($request, $checkout, 'paynow.short-link', ['token' => $token]);
        }

        return $this->protect($response);
    }

    public function checkout(Request $request, int $orderNumber, string $code, PayNowOrderLinkService $orders): Response
    {
        $checkout = $orders->resolveCheckout($orderNumber, $code) ?? ['state' => 'unavailable'];

        return $this->protect($this->checkoutResponse($request, $checkout, 'paynow.order-link', [
            'orderNumber' => $orderNumber, 'code' => $code,
        ]));
    }

    private function checkoutResponse(Request $request, array $checkout, string $route, array $parameters): Response
    {
        if (! isset($checkout['destination'])) {
            return $this->page($checkout);
        }
        if ($request->isMethod('GET') && $request->query('continue') === '1') {
            return redirect()->away($checkout['destination'], 302);
        }

        return $this->page($checkout, route($route, $parameters + ['continue' => '1'], false));
    }

    private function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    private function page(array $checkout, ?string $continueUrl = null): Response
    {
        [$title, $message] = match ($checkout['state']) {
            'redirect' => ['Płatność za zamówienie', 'Za chwilę przekierujemy Cię do PayNow. Jeśli przekierowanie nie nastąpi, kliknij „Zapłać”.'],
            'settled' => ['Zamówienie zostało już opłacone', 'Nie ma potrzeby ponawiania płatności.'],
            'expired' => ['Próba płatności wygasła', 'Poproś sprzedawcę o przygotowanie nowej płatności. Nadal możesz użyć tego samego linku.'],
            'waiting' => ['Nie można teraz kontynuować płatności', 'Jeżeli środki zostały pobrane, nie płać ponownie. W razie wątpliwości skontaktuj się ze sprzedawcą.'],
            default => ['Płatność nie jest dostępna', 'Link jest nieprawidłowy, wygasł lub nie jest już dostępny. Skontaktuj się ze sprzedawcą.'],
        };
        $nonce = Str::random(32);
        $response = response()->view('paynow.return', [
            'data' => [
                'state' => $checkout['state'], 'title' => $title, 'message' => $message,
                'amount' => $checkout['amount'] ?? null, 'currency' => $checkout['currency'] ?? null,
                'transactionId' => null, 'canRetry' => false, 'poll' => false, 'isTest' => $checkout['is_test'] ?? false,
            ],
            'nonce' => $nonce, 'statusUrl' => null, 'retryUrl' => null, 'continueUrl' => $continueUrl,
        ], $checkout['state'] === 'unavailable' ? 404 : 200);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");

        return $response;
    }
}
