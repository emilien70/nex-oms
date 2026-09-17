<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\SaveJpkTaxpayerProfileRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\JpkTaxOfficeCatalog;
use Modules\Invoices\Services\JpkTaxpayerProfileService;
use Modules\Invoices\Services\JpkV7m3Context;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;

class JpkTaxpayerProfileController extends Controller
{
    public function __construct(private readonly JpkTaxpayerProfileService $profiles, private readonly JpkTaxOfficeCatalog $offices) {}

    public function form(Request $request, array $problems = [], int $status = 200, ?string $source = null): Response
    {
        $profile = $this->profiles->current();
        $values = array_replace(array_fill_keys(JpkV7m3Context::TAXPAYER_FIELDS, ''), ['jpk_type' => 'person'], $profile?->formValues() ?? []);
        if (! $request->isMethod('get')) {
            foreach ($values as $field => $default) {
                $value = $request->input($field);
                $values[$field] = is_string($value) ? $value : '';
            }
        }
        $version = $request->isMethod('get') ? ($profile?->lock_version ?? 0) : $request->input('expected_lock_version');
        $sources = InvoiceSeries::query()->where('document_type', 'invoice')->orderBy('name')->get(['id', 'name']);

        return response()->view('invoices.jpk-profile.edit', [
            'values' => $values, 'profile' => $profile, 'version' => is_scalar($version) ? (string) $version : '',
            'offices' => $this->offices->all(), 'sources' => $sources, 'problems' => $problems, 'source' => $source,
        ], $status, ['Cache-Control' => 'private, no-store']);
    }

    public function save(SaveJpkTaxpayerProfileRequest $request): Response|RedirectResponse
    {
        try {
            $this->profiles->save($request->only(JpkV7m3Context::TAXPAYER_FIELDS), $request->integer('expected_lock_version'));
        } catch (InvoiceDomainException $exception) {
            return $this->form($request, [$exception->getMessage()], $exception->errorCode() === 'jpk_profile_conflict' ? 409 : 422);
        }

        return redirect()->route('invoices.jpk-profile.edit')->with('success', 'Zapisano dane podatnika do JPK.');
    }

    public function suggest(Request $request): Response
    {
        $id = $request->input('source_series_id');
        $series = is_string($id) && ctype_digit($id)
            ? InvoiceSeries::query()->where('document_type', 'invoice')->find($id, ['id', 'name', 'seller_tax_id', 'seller_name', 'seller_email', 'seller_phone']) : null;
        if ($series === null) {
            return $this->form($request, ['Wybierz jawnie serię będącą źródłem danych firmy.'], 422);
        }
        $suggestions = ['jpk_nip' => app(KsefFa3BuyerIdentityResolver::class)->normalizePolishNip($series->seller_tax_id),
            'jpk_email' => $series->seller_email, 'jpk_phone' => $series->seller_phone];
        if ($request->input('jpk_type') === 'organization') {
            $suggestions['jpk_name'] = $series->seller_name;
        }
        $request->merge(array_filter($suggestions, static fn ($value) => is_string($value) && $value !== ''));

        return $this->form($request, source: $series->name);
    }
}
