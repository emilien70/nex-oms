<?php

namespace Modules\Invoices\Services;

use Illuminate\Http\Request;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Support\InvoiceReturnContext;

final class SalesRegisterFormData
{
    public function build(Request $request): array
    {
        $series = InvoiceSeries::query()->whereIn('document_type', ['invoice', 'correction'])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('invoices', fn ($q) => $q->where('status', 'issued')))
            ->orderBy('document_type')->orderBy('name')->get(['id', 'name', 'document_type', 'is_active']);
        $years = [(int) now()->format('Y')];
        $currencies = $countries = [];
        foreach (Invoice::query()->whereIn('document_type', ['invoice', 'correction'])->where('status', 'issued')
            ->select(['id', 'issue_date', 'currency', 'buyer_snapshot'])->cursor() as $document) {
            $date = $document->getRawOriginal('issue_date');
            if (is_string($date) && preg_match('/^([1-9][0-9]{3})-/', $date, $matches)) {
                $years[] = (int) $matches[1];
            }
            $currency = strtoupper(trim((string) $document->currency));
            if (preg_match('/^[A-Z]{3}$/D', $currency)) {
                $currencies[$currency] = $currency;
            }
            $country = data_get($document->buyer_snapshot, 'country_code');
            if (is_string($country) && preg_match('/^[A-Za-z]{2}$/D', trim($country))) {
                $countries[strtoupper(trim($country))] = strtoupper(trim($country));
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);
        ksort($currencies);
        ksort($countries);
        $values = [
            'mode' => 'period', 'month' => now()->format('m'), 'year' => now()->format('Y'),
            'issue_from' => '', 'issue_to' => '', 'sale_from' => '', 'sale_to' => '',
            'series_ids' => $series->pluck('id')->all(), 'tax_id_presence' => 'all', 'currency' => '', 'country' => '',
            'format' => 'html', 'include_header' => '1', 'include_exchange_rates' => '0', 'include_ksef' => '1',
            'document_ids' => '[]',
            'jpk_bfk_outside_ksef' => '1', 'jpk_type' => '', 'jpk_nip' => '', 'jpk_office' => '',
            'jpk_email' => '', 'jpk_phone' => '', 'jpk_purpose' => '1', 'jpk_name' => '',
            'jpk_first_name' => '', 'jpk_last_name' => '', 'jpk_birth_date' => '',
        ];
        $jpkProfile = app(JpkTaxpayerProfileService::class)->current();
        $values = array_replace($values, $jpkProfile?->formValues() ?? []);
        if ($request->isMethod('post')) {
            foreach ($values as $field => $default) {
                $value = $request->input($field);
                if ($field === 'series_ids') {
                    $values[$field] = is_array($value) ? array_filter($value, 'is_scalar') : [];
                } elseif ($request->exists($field)) {
                    $values[$field] = is_scalar($value) ? (string) $value : '';
                } elseif ($field === 'jpk_bfk_outside_ksef') {
                    $values[$field] = $request->routeIs('invoices.sales-register.selected') ? '1' : '0';
                } elseif (str_starts_with($field, 'jpk_') && ! $request->routeIs('invoices.sales-register.selected')) {
                    $values[$field] = '';
                }
            }
            $values['mode'] = $request->routeIs('invoices.sales-register.selected') || $request->input('mode') === 'ids' ? 'ids' : 'period';
        }
        if ($values['currency'] !== '') {
            $currencies[$values['currency']] = $values['currency'];
        }
        if ($values['country'] !== '') {
            $countries[$values['country']] = $values['country'];
        }
        $returnRequest = Request::create('/', 'GET', [
            'return_to' => $request->input('return_to') === 'corrections' ? 'corrections' : 'invoices',
            'return_query' => $request->input('return_query'),
        ]);
        $returnContext = InvoiceReturnContext::fromRequest($returnRequest, InvoiceReturnContext::INVOICES);
        $ids = json_decode($values['document_ids'], true);

        return compact('series', 'years', 'currencies', 'countries', 'values', 'returnContext', 'jpkProfile') + [
            'offices' => app(JpkTaxOfficeCatalog::class)->all(),
            'selectedCount' => is_array($ids) && array_is_list($ids) ? count(array_unique($ids, SORT_REGULAR)) : 0,
            'months' => [1 => 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'],
        ];
    }
}
