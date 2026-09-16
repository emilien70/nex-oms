<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\SalesRegisterRequest;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterFormData;
use Modules\Invoices\Services\SalesRegisterHtmlPresenter;

class SalesRegisterController extends Controller
{
    public function create(Request $request, SalesRegisterFormData $form): Response
    {
        return response()->view('invoices.sales-register.form', $form->build($request), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function selected(SalesRegisterRequest $request, SalesRegisterDataService $data, SalesRegisterFormData $form): Response
    {
        try {
            $data->build($request->filters());
        } catch (InvoiceDomainException $exception) {
            return $this->invalid($request, $form, $exception);
        }

        return $this->create($request, $form);
    }

    public function export(SalesRegisterRequest $request, SalesRegisterDataService $data, SalesRegisterFormData $form, SalesRegisterHtmlPresenter $presenter): Response
    {
        try {
            $filters = $request->filters();
            $report = $data->build($filters);
        } catch (InvoiceDomainException $exception) {
            return $this->invalid($request, $form, $exception);
        }
        $options = array_map(static fn ($value) => (bool) $value, $request->safe()->only(['include_header', 'include_exchange_rates', 'include_ksef']));
        $filename = $filters->mode === 'ids' ? 'rejestr-sprzedazy-wybrane.html' : 'rejestr-sprzedazy-'.$filters->issueFrom.'_'.$filters->issueTo.'.html';

        return response()->view('invoices.sales-register.export', $presenter->present($report, $filters, $options), 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function invalid(Request $request, SalesRegisterFormData $form, InvoiceDomainException $exception): Response
    {
        $field = match ($exception->errorCode()) {
            'sales_register_documents_invalid' => 'document_ids',
            'sales_register_series_invalid' => 'series_ids',
            'sales_register_filters_invalid' => $exception->metadata()['field'] ?? 'mode',
            default => throw $exception,
        };

        return response()->view('invoices.sales-register.form', $form->build($request) + [
            'errors' => (new ViewErrorBag)->put('default', new MessageBag([$field => $exception->getMessage()])),
        ], 422, ['Cache-Control' => 'private, no-store']);
    }
}
