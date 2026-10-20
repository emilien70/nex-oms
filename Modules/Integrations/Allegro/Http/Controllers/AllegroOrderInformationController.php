<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Services\AllegroOrderInformationService;

class AllegroOrderInformationController extends Controller
{
    public function fees(Order $order, AllegroOrderInformationService $information): JsonResponse
    {
        try {
            $link = $information->refreshFees($order);

            return response()->json([
                'html' => view('orders.partials.allegro-fees', ['order' => $order, 'link' => $link])->render(),
            ]);
        } catch (AllegroApiException|AllegroImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function __invoke(Order $order, AllegroOrderInformationService $information): JsonResponse
    {
        try {
            $link = $information->refresh($order);

            return response()->json([
                'html' => view('orders.partials.allegro-details', ['order' => $order, 'link' => $link])->render(),
            ]);
        } catch (AllegroApiException|AllegroImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
