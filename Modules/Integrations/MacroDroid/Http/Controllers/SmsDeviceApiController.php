<?php

namespace Modules\Integrations\MacroDroid\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Services\SmsGatewayService;

class SmsDeviceApiController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['probe_id' => ['nullable', 'uuid']]);
        $authenticated = $request->attributes->get('sms_device');
        $result = DB::transaction(function () use ($data, $authenticated): array {
            $device = SmsDevice::query()->whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->enabled && $device->generation === $authenticated->generation, 401);
            $confirmed = isset($data['probe_id']) && $device->probe_id === $data['probe_id'] && $device->probe_expires_at?->isFuture();
            $device->last_seen_at = now();
            if ($confirmed) {
                $device->probe_confirmed_at = now();
                $device->probe_expires_at = null;
            }
            $device->save();

            return ['ok' => true, 'probe_confirmed' => (bool) $confirmed,
                'probe_id' => $device->probe_expires_at?->isFuture() ? $device->probe_id : ''];
        });

        return response()->json($result);
    }

    public function claim(Request $request, SmsGatewayService $gateway): JsonResponse
    {
        $data = $request->validate(['job_id' => ['nullable', 'uuid']]);
        $device = $request->attributes->get('sms_device');
        $result = isset($data['job_id']) ? $gateway->claim($device, $data['job_id']) : $gateway->claimNext($device);

        return response()->json($result, $result['available'] ? 200 : 409);
    }

    public function begin(Request $request, SmsGatewayService $gateway): JsonResponse
    {
        $data = $request->validate(['job_id' => ['required', 'uuid'], 'claim_token' => ['required', 'string', 'size:64']]);

        $result = $gateway->begin($request->attributes->get('sms_device'), $data['job_id'], $data['claim_token']);

        return response()->json($result, $result['may_send'] ? 200 : 409);
    }

    public function acknowledge(Request $request, SmsGatewayService $gateway): JsonResponse
    {
        $data = $request->validate(['job_id' => ['required', 'uuid'], 'claim_token' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'in:submitted,failed,uncertain']]);

        return response()->json($gateway->acknowledge($request->attributes->get('sms_device'), $data['job_id'], $data['claim_token'], $data['outcome']));
    }
}
