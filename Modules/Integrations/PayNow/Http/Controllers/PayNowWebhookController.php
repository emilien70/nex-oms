<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Integrations\PayNow\Exceptions\PayNowWebhookException;
use Modules\Integrations\PayNow\Services\PayNowWebhookService;

class PayNowWebhookController extends Controller
{
    public function __invoke(Request $request, PayNowWebhookService $webhook): Response
    {
        if (! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return response('', 415);
        }

        try {
            $webhook->handle(
                $request->getContent(),
                trim((string) $request->header('Signature')),
            );
        } catch (PayNowWebhookException $exception) {
            Log::warning('PayNow notification rejected.', [
                'status_code' => $exception->statusCode,
                'reason' => $exception->getMessage(),
            ]);

            return response('', $exception->statusCode);
        }

        return response('', 202);
    }
}
