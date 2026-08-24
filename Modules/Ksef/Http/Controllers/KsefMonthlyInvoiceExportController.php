<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Http\Requests\StoreKsefMonthlyInvoiceExportRequest;
use Modules\Ksef\Services\KsefMonthlyExportPeriod;
use Modules\Ksef\Services\KsefMonthlyInvoiceExportService;
use Modules\Ksef\ValueObjects\KsefMonthlyInvoiceExportResult;

class KsefMonthlyInvoiceExportController extends Controller
{
    public function __invoke(
        StoreKsefMonthlyInvoiceExportRequest $request,
        KsefMonthlyInvoiceExportService $exports,
        KsefMonthlyExportPeriod $periods,
    ): RedirectResponse {
        try {
            $result = $exports->export($request->validated('month'));
        } catch (KsefApiException $exception) {
            return redirect()
                ->route('integrations.ksef.edit', ['tab' => 'export'])
                ->withErrors(['export' => $exception->getMessage()]);
        }

        $redirect = redirect()->route('integrations.ksef.edit', ['tab' => 'export']);
        $message = $this->message($result, $periods->label($result->month));

        return $result->stoppedEarly
            ? $redirect->withErrors(['export' => $message])
            : $redirect->with('success', $message);
    }

    private function message(KsefMonthlyInvoiceExportResult $result, string $monthLabel): string
    {
        if ($result->eligibleCount === 0) {
            return "Brak nowych Faktur do przekazania do KSeF za {$monthLabel}.";
        }

        if ($result->stoppedEarly) {
            return 'Eksport został zatrzymany przed przetworzeniem pozostałych dokumentów. '
                ."Przekazano: {$result->submittedCount}. Błędy: {$result->failedCount}. "
                .'Nieprzetworzone dokumenty można bezpiecznie wyeksportować ponownie.';
        }

        return "Eksport zakończony. Przekazano: {$result->submittedCount}. Błędy: {$result->failedCount}.";
    }
}
