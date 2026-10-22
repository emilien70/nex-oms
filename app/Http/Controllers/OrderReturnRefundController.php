<?php

namespace App\Http\Controllers;

use App\Models\OrderReturn;
use App\Services\OrderReturnRefundService;
use Illuminate\Http\Request;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;

class OrderReturnRefundController extends Controller
{
    public function update(Request $request, OrderReturn $orderReturn, AllegroPaymentRefund $refund, OrderReturnRefundService $service)
    {
        $data = $request->validate(['expected_refund_version' => ['required', 'integer', 'min:0'], 'expected_return_version' => ['required', 'integer', 'min:0'],
            'products_value' => ['required', 'string', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/D'], 'delivery_value' => ['required', 'string', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/D']]);
        $service->allocate($orderReturn->id, $refund->id, $data);

        return redirect()->route('returns.show', $orderReturn);
    }
}
