<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Http\Requests\UpdateKsefSettingsRequest;
use Modules\Ksef\Services\KsefCertificateMaterialService;
use Modules\Ksef\Services\KsefMonthlyExportPeriod;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;
use Modules\Ksef\Services\KsefPaymentMethodMappingService;
use Modules\Ksef\Services\KsefSettingsService;

class KsefSettingsController extends Controller
{
    public function edit(
        Request $request,
        KsefSettingsService $settingsService,
        KsefPaymentMethodMappingService $paymentMappings,
        KsefMonthlyExportPeriod $exportPeriods,
        KsefOperationalEnvironmentPolicy $operationalEnvironments,
    ): View {
        $activeTab = match ($request->query('tab')) {
            'export' => 'export',
            'series' => 'series',
            'payment-types' => 'payment-types',
            default => 'connection',
        };
        $settings = $settingsService->get();

        return view('integrations.ksef.edit', [
            'settings' => $settings,
            'environmentOptions' => KsefEnvironment::cases(),
            'authenticationMethods' => KsefAuthenticationMethod::cases(),
            'zeroVatClassifications' => KsefZeroVatClassification::cases(),
            'paymentTypes' => KsefPaymentType::cases(),
            'tokenConfiguredByEnvironment' => $settingsService->tokenConfiguredByEnvironment(),
            'certificateConfiguredByEnvironment' => $settingsService->certificateConfiguredByEnvironment(),
            'certificateMetadataByEnvironment' => $settingsService->certificateMetadataByEnvironment(),
            'authenticationMethodByEnvironment' => $settingsService->authenticationMethodByEnvironment(),
            'connectionStatusByEnvironment' => $settingsService->connectionStatusByEnvironment(),
            'series' => $settingsService->seriesForConfiguration(),
            'paymentMethods' => $activeTab === 'payment-types'
                ? $paymentMappings->methodsForConfiguration()
                : collect(),
            'monthlyExportPeriods' => $exportPeriods->options(),
            'monthlyExportGateEnabled' => config('ksef.invoice_submission_enabled') === true,
            'monthlyExportEnvironmentAllowed' => $operationalEnvironments->allows($settings->environment),
            'activeTab' => $activeTab,
        ]);
    }

    public function update(
        UpdateKsefSettingsRequest $request,
        KsefSettingsService $settingsService,
        KsefCertificateMaterialService $certificateMaterialService,
    ): RedirectResponse {
        $data = $request->validated();
        $certificateMaterial = null;
        $certificateFile = $request->file('authentication_certificate');
        $privateKeyFile = $request->file('authentication_private_key');

        if ($certificateFile !== null && $privateKeyFile !== null) {
            $certificateMaterial = $certificateMaterialService->inspect(
                $certificateFile->get(),
                $privateKeyFile->get(),
                $data['authentication_private_key_passphrase'] ?? null,
            );
        }

        unset(
            $data['authentication_certificate'],
            $data['authentication_private_key'],
            $data['authentication_private_key_passphrase'],
        );

        $settingsService->update($data, $certificateMaterial);

        return redirect()->route('integrations.ksef.edit');
    }
}
