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
        $name = $request->route()?->getName();
        $publicPortal = in_array($name, ['customer-portal.show', 'customer-portal.document', 'customer-portal.details',
            'customer-portal.verify', 'customer-portal.image', 'customer-portal.logo', 'customer-portal.payment'], true);
        $customerHost = $urls->domain();
        $ownerHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $localOwner = app()->environment('local') && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        // Historical payment signatures bind their original host; they never authorize the portal or admin.
        $historicalReturn = in_array($name, ['paynow.return', 'paynow.return.status', 'paynow.return.retry'], true)
            && URL::hasValidSignature($request, true, ['paymentId', 'paymentStatus']);
        $paymentAlias = $name === 'paynow.short-link';
        if (($customerHost && ! $localOwner && ! $historicalReturn && ! $paymentAlias
                && ! in_array(strtolower($request->getHost()), [$ownerHost, $customerHost], true))
            || ($publicPortal && ! $urls->accepts($request))
            || ($customerHost && strtolower($request->getHost()) === $customerHost && ! $publicPortal
                && ! in_array($name, ['paynow.return', 'paynow.return.status', 'paynow.return.retry', 'paynow.short-link'], true))) {
            return response('', 404)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
        }

        if (($customerHost && strtolower($request->getHost()) === $customerHost)
            || ($historicalReturn && strtolower($request->getHost()) !== $ownerHost)) {
            config(['session.cookie' => 'nex_oms_customer_portal_session', 'session.domain' => null,
                'session.secure' => true, 'session.same_site' => 'lax']);
        }

        return $next($request);
    }
}
