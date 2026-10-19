<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Analytics\Http\Requests\BasicStatisticsRequest;
use Modules\Analytics\Services\AnalyticsFilterService;
use Modules\Analytics\Services\BasicStatisticsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BasicStatisticsCsvController extends Controller
{
    public function __invoke(
        BasicStatisticsRequest $request,
        AnalyticsFilterService $filters,
        BasicStatisticsService $statistics,
    ): StreamedResponse {
        $state = $filters->resolve($request->validated());
        $section = (string) $request->validated('section');
        $panel = collect($statistics->build($state)['panels'])->firstWhere('id', $section);

        abort_if($panel === null, 404);

        $filename = 'statystyki-'.$state['type'].'-'.$section.'-'.$state['period']['from']->toDateString().'-'.$state['period']['to']->toDateString().'.csv';

        return response()->streamDownload(function () use ($panel): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $panel['csv']['headers'], ';', '"', '\\');

            foreach ($panel['csv']['rows'] as $row) {
                fputcsv($stream, $row, ';', '"', '\\');
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
