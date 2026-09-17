<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\SalesRegisterRequest;
use Modules\Invoices\Services\JpkTaxpayerProfileService;
use Modules\Invoices\Services\JpkV7m3Context;
use Modules\Invoices\Services\JpkV7m3Exporter;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterFormData;
use Modules\Invoices\Services\SalesRegisterHtmlPresenter;
use Modules\Invoices\Services\SalesRegisterXlsxExporter;
use Modules\Invoices\Services\SalesRegisterXmlExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function loadProfile(Request $request, SalesRegisterFormData $form, JpkTaxpayerProfileService $profiles): Response
    {
        $profile = $profiles->current();
        if ($profile !== null) {
            $request->merge($profile->formValues());
        }

        return $this->create($request, $form);
    }

    public function export(SalesRegisterRequest $request, SalesRegisterDataService $data, SalesRegisterFormData $form, SalesRegisterHtmlPresenter $presenter, SalesRegisterXlsxExporter $xlsx, SalesRegisterXmlExporter $xml, JpkV7m3Exporter $jpk): Response|BinaryFileResponse
    {
        try {
            $filters = $request->filters();
            $report = $data->build($filters);
            if ($request->validated('format') === 'jpk_v7m3') {
                $context = new JpkV7m3Context($request->safe()->only(['jpk_year', 'jpk_month', 'jpk_type', 'jpk_nip', 'jpk_office',
                    'jpk_email', 'jpk_phone', 'jpk_purpose', 'jpk_name', 'jpk_first_name', 'jpk_last_name', 'jpk_birth_date']));
                $markers = array_map(static fn ($value) => $value ?? '', $request->validated('jpk_markers', []));
                $review = $jpk->prepare($report, $context, $markers);
                $download = $request->validated('jpk_action') === 'download';
                if ($download && ! hash_equals($review['fingerprint'], $request->validated('jpk_fingerprint'))) {
                    $review['errors'][] = 'Dane lub zakres eksportu zmieniły się. Sprawdź ponownie listę i potwierdź pobranie.';
                } elseif ($download && $review['errors'] === []) {
                    return $jpk->download($review, $context);
                }

                unset($review['xml']);

                return response()->view('invoices.sales-register.form', $form->build($request) + ['jpkReview' => $review],
                    $download ? 422 : 200, ['Cache-Control' => 'private, no-store']);
            }
            if ($request->validated('format') === 'xlsx') {
                return $xlsx->download($report, $filters);
            }
            if ($request->validated('format') === 'xml') {
                return $xml->download($report, $filters);
            }
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
            'sales_register_xlsx_failed', 'sales_register_xlsx_invalid_value',
            'sales_register_xlsx_text_limit', 'sales_register_xlsx_row_limit' => 'format',
            'sales_register_xml_invalid', 'sales_register_xml_failed' => 'format',
            'sales_register_jpk_invalid' => 'format',
            default => throw $exception,
        };

        return response()->view('invoices.sales-register.form', $form->build($request) + [
            'errors' => (new ViewErrorBag)->put('default', new MessageBag([$field => $exception->getMessage()])),
        ], 422, ['Cache-Control' => 'private, no-store']);
    }
}
