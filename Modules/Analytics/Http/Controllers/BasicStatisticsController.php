<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Modules\Analytics\Http\Requests\BasicStatisticsRequest;
use Modules\Analytics\Services\AnalyticsFilterService;
use Modules\Analytics\Services\BasicStatisticsService;

class BasicStatisticsController extends Controller
{
    public function __invoke(
        BasicStatisticsRequest $request,
        AnalyticsFilterService $filters,
        BasicStatisticsService $statistics,
    ): View {
        $state = $filters->resolve($request->validated());
        $report = $statistics->build($state);

        return view('analytics.basic', [
            'state' => $state,
            'report' => $report,
            'previousUrl' => route('analytics.basic', $filters->shiftedQuery($state, -1)),
            'nextUrl' => route('analytics.basic', $filters->shiftedQuery($state, 1)),
        ]);
    }
}
