<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Automation\Services\AutomationActivityFeed;

class AutomationActivityController extends Controller
{
    public function index(Request $request, AutomationActivityFeed $feed): JsonResponse
    {
        $validated = $request->validate(['order_id' => ['required', 'integer', 'min:1']]);

        return response()
            ->json(['activities' => $feed->recent((int) $validated['order_id'])])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
