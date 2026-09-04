<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Throwable;

final class KsefOfflineIssuanceController extends Controller
{
    public function offline24(
        Invoice $invoice,
        KsefOfflineIssuanceService $issuances,
    ): RedirectResponse {
        return $this->issue(
            $invoice,
            fn () => $issuances->issueOffline24($invoice),
            'Offline24',
            'ksef_offline24',
        );
    }

    public function plannedUnavailability(
        Invoice $invoice,
        KsefOfflineIssuanceService $issuances,
    ): RedirectResponse {
        return $this->issue(
            $invoice,
            fn () => $issuances->issuePlannedUnavailability($invoice),
            'Offline – niedostępność KSeF',
            'ksef_offline_planned_unavailability',
        );
    }

    public function failure(
        Invoice $invoice,
        KsefOfflineIssuanceService $issuances,
    ): RedirectResponse {
        return $this->issue(
            $invoice,
            fn () => $issuances->issueFailure($invoice),
            'awaryjnym',
            'ksef_offline_failure',
        );
    }

    private function issue(
        Invoice $invoice,
        callable $operation,
        string $label,
        string $errorKey,
    ): RedirectResponse {
        try {
            $operation();
        } catch (KsefApiException|InvoiceDomainException $exception) {
            return back()->withErrors([$errorKey => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd lokalnego wystawienia Offline.', [
                'invoice_id' => $invoice->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                $errorKey => 'Nie udało się bezpiecznie wystawić Faktury Offline.',
            ]);
        }

        return back()->with('status', 'Faktura została wystawiona lokalnie w trybie '.$label.'.');
    }
}
