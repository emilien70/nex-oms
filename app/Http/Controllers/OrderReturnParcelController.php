<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOrderReturnParcelRequest;
use App\Models\OrderReturn;
use App\Models\OrderReturnParcel;
use App\Services\OrderReturnParcelService;
use Illuminate\Http\Request;
use Modules\Shipments\Jobs\RefreshReturnParcelJob;

class OrderReturnParcelController extends Controller
{
    public function store(SaveOrderReturnParcelRequest $request, OrderReturn $orderReturn, OrderReturnParcelService $service)
    {
        $service->saveManual($orderReturn, null, $request->validated(), $request->integer('expected_lock_version'));

        return to_route('returns.show', $orderReturn)->with('success', 'Dodano przesyłkę zwrotną.');
    }

    public function update(SaveOrderReturnParcelRequest $request, OrderReturn $orderReturn, OrderReturnParcel $parcel, OrderReturnParcelService $service)
    {
        abort_unless($parcel->order_return_id === $orderReturn->id && $parcel->is_manual, 404);
        $service->saveManual($orderReturn, $parcel, $request->validated(), $request->integer('expected_lock_version'));

        return to_route('returns.show', $orderReturn)->with('success', 'Zapisano przesyłkę zwrotną.');
    }

    public function history(Request $request, OrderReturn $orderReturn, OrderReturnParcel $parcel)
    {
        abort_unless($parcel->order_return_id === $orderReturn->id, 404);
        $events = $parcel->trackingEvents()->where('tracking_identity', $parcel->identity())->paginate(20)->withQueryString();
        $data = compact('orderReturn', 'parcel', 'events');

        return $request->expectsJson()
            ? response()->json(['html' => view('returns._parcel-history', $data)->render()])->header('Cache-Control', 'no-store')
            : response()->view('returns.parcel-history', $data)->header('Cache-Control', 'no-store');
    }

    public function refresh(OrderReturn $orderReturn, OrderReturnParcel $parcel)
    {
        abort_unless($parcel->order_return_id === $orderReturn->id, 404);
        if (! $parcel->isTerminal()) {
            RefreshReturnParcelJob::dispatch($parcel->id);
        }

        return back()->with('success', 'Zlecono sprawdzenie statusu przesyłki zwrotnej.');
    }
}
