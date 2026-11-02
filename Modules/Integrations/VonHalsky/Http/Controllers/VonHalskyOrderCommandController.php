<?php

namespace Modules\Integrations\VonHalsky\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderCommandService;

class VonHalskyOrderCommandController extends Controller
{
    public function __invoke(Request $request, Order $order, int $command, VonHalskyOrderCommandService $commands): RedirectResponse
    {
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        $commands->retry($order, $command, (int) $data['expected_lock_version']);

        return redirect()->route('orders.show', $order)->with('success', 'Zlecono ponowne przekazanie statusu do Von Halsky.');
    }
}
