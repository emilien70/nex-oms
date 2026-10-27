<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\CustomerPortal\Http\Middleware\RestrictCustomerPortalHost;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', RestrictCustomerPortalHost::class);
        $middleware->validateCsrfTokens(except: [
            'api/nex-printer/v1/*',
            'api/paynow/v1/notifications',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'api_key',
            'api_token',
            'client_secret',
            'authentication_private_key_passphrase',
            'offline_private_key_passphrase',
            'signature_key',
            'smtp_password',
            'portal_value',
        ]);
    })->create();
