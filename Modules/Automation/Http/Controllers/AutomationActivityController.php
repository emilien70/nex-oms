<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Automation\Services\AutomationActivityFeed;

class AutomationActivityController extends Controller
{
    public function index(AutomationActivityFeed $feed): JsonResponse
    {
        return response()
            ->json(['activities' => $feed->recent()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
