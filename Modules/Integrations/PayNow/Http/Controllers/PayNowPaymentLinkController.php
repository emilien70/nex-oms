<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Services\PayNowPaymentLinkService;
use Modules\Integrations\PayNow\Services\PayNowShortLinkService;

class PayNowPaymentLinkController extends Controller
{
    public function __invoke(Order $order, PayNowPaymentLinkService $paymentLinks, PayNowShortLinkService $shortLinks): JsonResponse
    {
        if (! $shortLinks->isAvailable()) {
            return response()->json(['message' => 'Krótkie linki PayNow wymagają wykonania migracji aplikacji.'], 503);
        }

        try {
            $result = $paymentLinks->createOrReuse($order);
        } catch (PayNowApiException $exception) {
            $status = $exception->statusCode === null || $exception->statusCode >= 500
                ? 503
                : 422;

            return response()->json([
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'url' => $shortLinks->forPayment($result->payment),
            'reused' => $result->reused,
            'amount' => $result->payment->amount,
            'currency' => $result->payment->currency,
            'message' => $result->reused
                ? 'Skopiowano istniejący link do płatności PayNow.'
                : 'Utworzono i skopiowano link do płatności PayNow.',
            'order' => [
                'label' => 'Zamówienie '.$order->getKey(),
                'url' => route('orders.show', $order),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
