<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusSetting;
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
            'inboundStatusMappings.statusSetting',
            'outboundStatusMappings.statusSetting',
        ]);
        $recentImportAttempts = $account->orderImportAttempts()
            ->with('order')
            ->latest('discovered_at')
            ->limit(10)
            ->get();
        $identifier = $request->validated('order_identifier');
        $requestedTab = (string) $request->query('tab', 'connection');
        $activeTab = in_array($requestedTab, ['connection', 'orders', 'statuses', 'payments'], true)
            ? $requestedTab
            : 'connection';
        $preview = null;
        $previewError = null;

        if ($identifier) {
            $activeTab = 'orders';

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
            'activeTab' => $activeTab,
            'orderStatuses' => OrderStatusSetting::orderedSettings(),
            'recentImportAttempts' => $recentImportAttempts,
            'inboundStatusMappings' => $account->inboundStatusMappings
                ->mapWithKeys(function ($mapping): array {
                    $statusCode = $mapping->statusSetting?->status;

                    return $statusCode
                        ? [(string) $mapping->external_order_state_id => $statusCode]
                        : [];
                }),
            'outboundStatusMappings' => $account->outboundStatusMappings
                ->mapWithKeys(function ($mapping): array {
                    $statusCode = $mapping->statusSetting?->status;

                    return $statusCode
                        ? [$statusCode => (string) $mapping->external_order_state_id]
                        : [];
                }),
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
