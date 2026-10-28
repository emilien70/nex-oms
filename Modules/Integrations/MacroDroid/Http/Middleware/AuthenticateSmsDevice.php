<?php

namespace Modules\Integrations\MacroDroid\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSmsDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->isSecure() || app()->environment('testing'), 403);
        $device = SmsDevice::configured();
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) === 64 && $device?->enabled && $device->token_hash
            && hash_equals($device->token_hash, hash('sha256', $token)), 401);
        $request->attributes->set('sms_device', $device);

        return $next($request)->withHeaders(['Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
