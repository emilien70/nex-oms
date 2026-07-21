<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RespondsToOrderAjax
{
    protected function orderMutationResponse(Request $request, array $refresh, RedirectResponse $fallback): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'saved' => true,
                'refresh' => $refresh,
            ]);
        }

        return $fallback;
    }
}
