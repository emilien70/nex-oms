<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Services\KsefConnectionTestService;

class KsefConnectionTestController extends Controller
{
    public function __invoke(KsefConnectionTestService $connectionTest): RedirectResponse
    {
        $connectionTest->test();

        return redirect()->route('integrations.ksef.edit');
    }
}
