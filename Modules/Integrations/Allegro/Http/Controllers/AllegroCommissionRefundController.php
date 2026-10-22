<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Services\AllegroCommissionRefundService;

class AllegroCommissionRefundController extends Controller
{
    public function create(Request $request, OrderReturn $orderReturn, AllegroCommissionRefundService $service)
    {
        try {
            $context = $service->context($orderReturn);
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['commission_refund' => $e->getMessage()]);
        }
        $quote = ['token' => (string) Str::uuid(), 'hash' => $context['hash'], 'expires' => now()->addMinutes(5)->timestamp];
        $request->session()->put('commission_refund_quotes.'.$orderReturn->id, $quote);

        return response()->view('returns.commission-refund', compact('orderReturn', 'context', 'quote'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, OrderReturn $orderReturn, AllegroCommissionRefundService $service)
    {
        $data = $request->validate(['quote_token' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:selected,quantity'],
            'items.*.selected' => ['sometimes', 'boolean'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000']]);
        try {
            $service->submit($orderReturn, $data, $request->session()->get('commission_refund_quotes.'.$orderReturn->id, []));
        } catch (AllegroApiException $e) {
            throw ValidationException::withMessages(['commission_refund' => $e->getMessage()]);
        }

        return redirect()->route('returns.show', $orderReturn);
    }
}
