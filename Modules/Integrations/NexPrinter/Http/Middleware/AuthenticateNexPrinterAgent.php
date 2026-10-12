<?php

namespace Modules\Integrations\NexPrinter\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Integrations\NexPrinter\Models\NexPrinterAgent;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNexPrinterAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();
        $agent = is_string($plainToken) && $plainToken !== ''
            ? NexPrinterAgent::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->first()
            : null;

        if ($agent === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unauthenticated_agent',
                    'message' => 'Nieprawidłowe uwierzytelnienie NEX Printer.',
                ],
            ], 401);
        }

        $request->attributes->set('nex_printer_agent', $agent);

        return $next($request);
    }
}
