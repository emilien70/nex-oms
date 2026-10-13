<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Http\Requests\PreviewPrestashopOrderRequest;
use Modules\Integrations\Prestashop\Http\Requests\UpdatePrestashopAccountRequest;
use Modules\Integrations\Prestashop\Services\PrestashopAccountService;
use Modules\Integrations\Prestashop\Services\PrestashopOrderPreviewService;
use Modules\Invoices\Services\InvoiceMoneyFormatter;

class PrestashopSettingsController extends Controller
{
    public function edit(
        PreviewPrestashopOrderRequest $request,
        PrestashopAccountService $accountService,
        PrestashopOrderPreviewService $previewService,
        InvoiceMoneyFormatter $moneyFormatter,
    ): View {
        $account = $accountService->get()->load([
            'orderStates' => fn ($query) => $query->orderBy('external_id'),
        ]);
        $identifier = $request->validated('order_identifier');
        $preview = null;
        $previewError = null;

        if ($identifier) {
            try {
                $preview = $previewService->preview($account, $identifier);
            } catch (PrestashopApiException|PrestashopImportException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        return view('integrations.prestashop.edit', [
            'account' => $account,
            'credentialsConfigured' => $account->hasCompleteCredentials(),
            'canActivate' => $account->last_test_status === PrestashopConnectionTestStatus::Success,
            'orderIdentifier' => $identifier,
            'orderPreview' => $preview,
            'orderPreviewError' => $previewError,
            'moneyFormatter' => $moneyFormatter,
        ]);
    }

    public function update(
        UpdatePrestashopAccountRequest $request,
        PrestashopAccountService $accountService,
    ): RedirectResponse {
        $accountService->update($request->validated());

        return redirect()
            ->route('integrations.prestashop.edit')
            ->with('success', 'Ustawienia PrestaShop zostały zapisane.');
    }
}
