<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Modules\Integrations\PayNow\Services\PayNowShortLinkService;
use Symfony\Component\HttpFoundation\Response;

class PayNowShortLinkController extends Controller
{
    public function __invoke(string $token, PayNowShortLinkService $links): Response
    {
        // Query parameters cannot replace the persisted target or the payment identity.
        $target = $links->destination($token);
        if ($target !== null) {
            $response = redirect()->away($target, 302);
        } else {
            $nonce = Str::random(32);
            $response = response()->view('paynow.return', [
                'data' => [
                    'state' => 'unavailable', 'title' => 'Płatność nie jest dostępna',
                    'message' => 'Link jest nieprawidłowy, wygasł lub nie jest już dostępny. Skontaktuj się ze sprzedawcą.',
                    'amount' => null, 'currency' => null, 'transactionId' => null,
                    'canRetry' => false, 'poll' => false, 'isTest' => false,
                ],
                'nonce' => $nonce, 'statusUrl' => null, 'retryUrl' => null,
            ], 404);
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
