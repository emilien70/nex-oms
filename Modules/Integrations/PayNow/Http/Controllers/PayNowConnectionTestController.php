<?php

namespace Modules\Integrations\PayNow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Services\PayNowConnectionTestService;

class PayNowConnectionTestController extends Controller
{
    public function __invoke(PayNowConnectionTestService $connectionTest): RedirectResponse
    {
        $account = $connectionTest->test();
        $flashKey = $account->last_test_status === PayNowConnectionTestStatus::Success
            ? 'success'
            : 'error';

        return redirect()
            ->route('integrations.paynow.edit')
            ->with($flashKey, $account->last_test_message);
    }
}
