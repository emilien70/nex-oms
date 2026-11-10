<?php

namespace Modules\CustomerPortal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\CustomerPortal\Services\PortalUrlService;
use Symfony\Component\HttpFoundation\Response;

class RestrictCustomerPortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $urls = app(PortalUrlService::class);
        // Keep the validated value only while handling HTTP, never between queue jobs.
        $request->attributes->set(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE, $urls->domain());
        try {
            return $this->handleRequest($request, $next, $urls);
        } finally {
            $request->attributes->remove(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE);
        }
    }

    private function handleRequest(Request $request, Closure $next, PortalUrlService $urls): Response
    {
        $name = $request->route()?->getName();
        $customerLanding = $urls->isCustomerLanding($request);
        $information = $name === 'customer-portal.information';
        $publicPortal = $customerLanding || $information || in_array($name, ['customer-portal.show', 'customer-portal.document', 'customer-portal.details',
            'customer-portal.verify', 'customer-portal.image', 'customer-portal.logo', 'customer-portal.payment'], true);
        $customerHost = $urls->domain();
        $ownerHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $host = strtolower($request->getHost());
        $onCustomerHost = $customerHost !== null && $host === $customerHost;
        $localOwner = app()->environment('local') && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        // Historical payment signatures bind their original host; they never authorize the portal or admin.
        $paymentReturn = in_array($name, ['paynow.return', 'paynow.return.status', 'paynow.return.retry'], true);
        $historicalReturn = $paymentReturn
            && URL::hasValidSignature($request, true, ['paymentId', 'paymentStatus']);
        $paymentAlias = $name === 'paynow.short-link';
        if (($request->is('/') && ! $localOwner && ! $customerLanding && $host !== $ownerHost)
            || ($customerHost && ! $localOwner && ! $historicalReturn && ! $paymentAlias
                && ! in_array($host, [$ownerHost, $customerHost], true))
            || ($information && ! $localOwner && ! $onCustomerHost)
            || ($publicPortal && ! ($information && $localOwner) && ! $urls->accepts($request))
            || ($onCustomerHost && ! $publicPortal && ! $paymentReturn && ! $paymentAlias)) {
            return response('', 404)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
        }

        if (($onCustomerHost && ! $information) || ($historicalReturn && $host !== $ownerHost)) {
            config(['session.cookie' => 'nex_oms_customer_portal_session', 'session.domain' => null,
                'session.secure' => true, 'session.same_site' => 'lax']);
        }

        $response = $next($request);
        if ($onCustomerHost && ($publicPortal || $paymentReturn || $paymentAlias)) {
            // Customer forms use the session-backed form token, not Laravel's auxiliary cookie.
            $response->headers->removeCookie('XSRF-TOKEN', config('session.path', '/'));
        }

        return $response;
    }
}
