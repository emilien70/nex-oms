<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceBulkDeleteRequest;
use Modules\Invoices\Http\Requests\ProformaBulkDeleteRequest;
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
        return $this->delete(
            $request,
            $deletion,
            $contextFactory,
            InvoiceDocumentType::Invoice,
        );
    }

    public function proformas(
        ProformaBulkDeleteRequest $request,
        InvoiceDeletionService $deletion,
        InvoiceOperationContextFactory $contextFactory,
    ): RedirectResponse {
        return $this->delete(
            $request,
            $deletion,
            $contextFactory,
            InvoiceDocumentType::Proforma,
        );
    }

    private function delete(
        InvoiceBulkDeleteRequest $request,
        InvoiceDeletionService $deletion,
        InvoiceOperationContextFactory $contextFactory,
        InvoiceDocumentType $documentType,
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
                $documentType,
            );

            return back()->with(
                'success',
                $documentType === InvoiceDocumentType::Proforma
                    ? trans_choice(
                        '{1} Usunięto :count Pro formę.|[2,4] Usunięto :count Pro formy.|[5,*] Usunięto :count Pro form.',
                        $deletedCount,
                        ['count' => $deletedCount],
                    )
                    : trans_choice(
                        '{1} Usunięto :count Fakturę.|[2,4] Usunięto :count Faktury.|[5,*] Usunięto :count Faktur.',
                        $deletedCount,
                        ['count' => $deletedCount],
                    ),
            );
        } catch (InvoiceDomainException $exception) {
            return back()->withErrors(['invoice_ids' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd zbiorczego usuwania dokumentów sprzedaży.', [
                'invoice_ids' => $invoiceIds,
                'document_type' => $documentType->value,
                'exception' => $exception,
            ]);

            return back()->withErrors([
                'invoice_ids' => $documentType === InvoiceDocumentType::Proforma
                    ? 'Nie udało się usunąć zaznaczonych Pro form. Spróbuj ponownie.'
                    : 'Nie udało się usunąć zaznaczonych Faktur. Spróbuj ponownie.',
            ]);
        }
    }
}
