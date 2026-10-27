<?php

namespace Modules\CustomerPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\CustomerPortal\Services\PortalPaymentService;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Symfony\Component\HttpFoundation\Response;

class PortalPaymentController extends Controller
{
    public function __invoke(Request $request, string $orderNumber, string $token, string $action, PortalAccessService $access, PortalPaymentService $payments, PortalUrlService $urls): Response
    {
        $grant = $access->resolve($token, orderNumber: (int) $orderNumber);
        if (! $grant) {
            return $this->secure(response('', 404));
        }
        $intent = $request->input('intent');
        if (! is_string($intent) || preg_match('/^[a-f0-9]{64}$/D', $intent) !== 1) {
            return $this->secure(response('', 422));
        }
        try {
            $result = $payments->perform($token, $intent, $action, (int) $orderNumber);
            if ($result['url']) {
                $payments->rememberReturn($request, $result);

                return $this->secure(redirect()->to($result['url'], 303));
            }

            return $this->secure(redirect()->to($urls->link($grant['link']), 303));
        } catch (ValidationException|PayNowApiException) {
            return $this->secure(redirect()->to($urls->link($grant['link']), 303)
                ->with('portal_payment_error', 'Nie można teraz kontynuować płatności. Odśwież stronę i sprawdź status lub skontaktuj się ze sprzedawcą.'));
        }
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
