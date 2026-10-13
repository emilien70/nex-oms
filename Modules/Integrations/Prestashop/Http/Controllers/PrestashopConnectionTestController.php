<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Services\PrestashopConnectionTestService;

class PrestashopConnectionTestController extends Controller
{
    public function __invoke(PrestashopConnectionTestService $connectionTest): RedirectResponse
    {
        $account = $connectionTest->test();
        $flashKey = $account->last_test_status === PrestashopConnectionTestStatus::Success
            ? 'success'
            : 'error';

        return redirect()
            ->route('integrations.prestashop.edit')
            ->with($flashKey, $account->last_test_message);
    }
}
