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
    public function __invoke(
        Invoice $invoice,
        KsefOfflineIssuanceService $issuances,
    ): RedirectResponse {
        try {
            $issuances->issueOffline24($invoice);
        } catch (KsefApiException|InvoiceDomainException $exception) {
            return back()->withErrors(['ksef_offline24' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd lokalnego wystawienia Offline24.', [
                'invoice_id' => $invoice->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'ksef_offline24' => 'Nie udało się bezpiecznie wystawić Faktury w trybie Offline24.',
            ]);
        }

        return back()->with('status', 'Faktura została wystawiona lokalnie w trybie Offline24.');
    }
}
