<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Http\Requests\UpdatePayNowAccountRequest;
use Modules\Integrations\PayNow\Services\PayNowAccountService;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;

class PayNowSettingsController extends Controller
{
    public function edit(PayNowAccountService $accountService): View
    {
        $account = $accountService->get();

        return view('integrations.paynow.edit', [
            'account' => $account,
            'environments' => PayNowEnvironment::cases(),
            'credentialsConfigured' => $account->hasCompleteCredentials(),
            'canActivate' => $account->last_test_status === PayNowConnectionTestStatus::Success,
            'notificationUrl' => route('api.paynow.notifications'),
            'returnUrl' => app(PayNowReturnUrlService::class)->base(),
        ]);
    }

    public function update(
        UpdatePayNowAccountRequest $request,
        PayNowAccountService $accountService,
    ): RedirectResponse {
        $accountService->update($request->validated());

        return redirect()
            ->route('integrations.paynow.edit')
            ->with('success', 'Ustawienia PayNow zostały zapisane.');
    }
}
