<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceBulkDeleteRequest;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Throwable;

class InvoiceBulkDeletionController extends Controller
{
    public function __invoke(
        InvoiceBulkDeleteRequest $request,
        InvoiceDeletionService $deletion,
        InvoiceOperationContextFactory $contextFactory,
    ): RedirectResponse {
        $invoiceIds = array_map('intval', $request->validated('invoice_ids'));
        $lockVersions = $request->validated('lock_versions');
        $expectedLockVersions = [];

        foreach ($invoiceIds as $invoiceId) {
            $expectedLockVersions[$invoiceId] = (int) $lockVersions[$invoiceId];
        }

        try {
            $deletedCount = $deletion->deleteMany(
                $expectedLockVersions,
                $contextFactory->manual($request),
            );

            return back()->with(
                'success',
                trans_choice(
                    '{1} Usunięto :count Fakturę.|[2,4] Usunięto :count Faktury.|[5,*] Usunięto :count Faktur.',
                    $deletedCount,
                    ['count' => $deletedCount],
                ),
            );
        } catch (InvoiceDomainException $exception) {
            return back()->withErrors(['invoice_ids' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd zbiorczego usuwania Faktur VAT.', [
                'invoice_ids' => $invoiceIds,
                'exception' => $exception,
            ]);

            return back()->withErrors([
                'invoice_ids' => 'Nie udało się usunąć zaznaczonych Faktur. Spróbuj ponownie.',
            ]);
        }
    }
}
