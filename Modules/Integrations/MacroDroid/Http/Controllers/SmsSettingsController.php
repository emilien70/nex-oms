<?php

namespace Modules\Integrations\MacroDroid\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\MacroDroid\Jobs\NotifySmsDeviceJob;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Services\SmsPhoneNumber;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;
use Modules\Integrations\MacroDroid\Services\SmsSegments;

class SmsSettingsController extends Controller
{
    public function configuration(string $kind)
    {
        abort_unless(in_array($kind, ['sms', 'probe'], true), 404);
        $profile = json_decode(file_get_contents(resource_path('macrodroid/'.$kind.'.macro')), true, 512, JSON_THROW_ON_ERROR);
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $publicHost = str_contains($host, '.') && $host !== 'localhost'
            && (! filter_var($host, FILTER_VALIDATE_IP)
                || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if (filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https' && $publicHost
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])) {
            foreach ($profile['macro']['localVariables'] as &$variable) {
                if ($variable['m_name'] === 'oms_url') {
                    $variable['m_stringValue'] = $url;
                }
            }
            unset($variable);
        }

        return response(json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="NEX-OMS-'.($kind === 'sms' ? 'SMS' : 'test-polaczenia').'.macro"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function edit()
    {
        $ready = Schema::hasTable('macrodroid_devices');

        return view('integrations.macrodroid.edit', [
            'ready' => $ready, 'device' => SmsDevice::configured(),
            'messages' => $ready ? SmsMessage::query()->latest('id')->limit(20)->get() : collect(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'enabled' => ['required', 'boolean'],
            'sender_phone' => ['nullable', 'string', 'max:32'], 'webhook_url' => ['nullable', 'string', 'max:500'],
            'per_minute' => ['required', 'integer', 'between:1,30'], 'per_day' => ['required', 'integer', 'between:1,1000'],
            'lifetime_minutes' => ['required', 'integer', 'between:5,1440'], 'max_parts' => ['required', 'integer', 'between:1,10'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);
        if (! empty($data['webhook_url']) && ! NotifySmsDeviceJob::validWebhook($data['webhook_url'])) {
            throw ValidationException::withMessages(['webhook_url' => 'Podaj dokladny adres HTTPS webhooka trigger.macrodroid.com, bez parametrow.']);
        }
        if (! empty($data['sender_phone'])) {
            $data['sender_phone'] = SmsPhoneNumber::normalize($data['sender_phone']);
        }
        $this->change($data['expected_lock_version'], function (SmsDevice $device) use ($data): void {
            $webhook = $data['webhook_url'] ?? null;
            if ($data['enabled'] && (! ($webhook ?: $device->webhook_url) || ! $device->token_hash)) {
                throw ValidationException::withMessages(['enabled' => 'Najpierw zapisz webhook i wygeneruj klucz urzadzenia.']);
            }
            $device->fill(collect($data)->except(['expected_lock_version', 'webhook_url'])->all());
            if ($webhook) {
                $device->webhook_url = $webhook;
            }
        });

        return redirect()->route('integrations.macrodroid.edit')->with('success', 'Zapisano ustawienia MacroDroid. Nierozpoczete zlecenia poprzedniej konfiguracji anulowano.');
    }

    public function rotate(Request $request)
    {
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        $token = Str::random(64);
        $device = $this->change($data['expected_lock_version'], function (SmsDevice $device) use ($token): void {
            $device->token_hash = hash('sha256', $token);
            $device->enabled = false;
        });

        return response()->json(['access_token' => $token, 'lock_version' => $device->lock_version])
            ->header('Cache-Control', 'private, no-store');
    }

    public function probe(Request $request)
    {
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data): void {
            $device = SmsDevice::query()->where('singleton_key', 'primary')->lockForUpdate()->first();
            app(SmsPreparationService::class)->assertEnabled($device);
            abort_unless($device->lock_version === (int) $data['expected_lock_version'], 409);
            $id = (string) Str::uuid();
            $device->update(['probe_id' => $id, 'probe_expires_at' => now()->addMinutes(5), 'probe_confirmed_at' => null]);
            NotifySmsDeviceJob::dispatch($id, $device->generation, true)->afterCommit();
        });

        return back()->with('success', 'Zlecono test polaczenia bez SMS. Odswiez strone, aby sprawdzic potwierdzenie telefonu.');
    }

    public function testSms(Request $request)
    {
        $data = $request->validate(['recipient' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['required', 'uuid'], 'confirm' => ['accepted']]);
        $phone = SmsPhoneNumber::normalize($data['recipient']);
        DB::transaction(function () use ($data, $phone): void {
            $device = SmsDevice::query()->where('singleton_key', 'primary')->lockForUpdate()->first();
            app(SmsPreparationService::class)->assertEnabled($device);
            $intent = hash_hmac('sha256', 'test:'.$phone, (string) config('app.key'));
            $existing = SmsMessage::query()->where('batch_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->intent_hash, $intent), 409);

                return;
            }
            $body = 'NEX-OMS: test bramki SMS MacroDroid.';
            $message = SmsMessage::query()->create([
                'public_id' => (string) Str::uuid(), 'device_id' => $device->id, 'device_generation' => $device->generation,
                'source' => 'test', 'status' => 'queued', 'idempotency_key' => $data['idempotency_key'],
                'batch_key' => $data['idempotency_key'],
                'intent_hash' => $intent, 'recipient' => $phone, 'body' => $body, 'actor_name' => 'Operator NEX-OMS',
                ...app(SmsSegments::class)->measure($body), 'expires_at' => now()->addMinutes(5),
            ]);
            NotifySmsDeviceJob::dispatch($message->public_id, $device->generation)->afterCommit();
        });

        return back()->with('success', 'Dodano SMS testowy do kolejki. To jeszcze nie potwierdzenie wysylki.');
    }

    private function change(int $expected, callable $update): SmsDevice
    {
        abort_unless(Schema::hasTable('macrodroid_devices'), 503, 'Uruchom migracje SMS.');

        return DB::transaction(function () use ($expected, $update): SmsDevice {
            SmsDevice::query()->firstOrCreate(['singleton_key' => 'primary']);
            $device = SmsDevice::query()->where('singleton_key', 'primary')->lockForUpdate()->firstOrFail();
            abort_unless($device->lock_version === $expected, 409, 'Ustawienia zmienily sie. Odswiez strone.');
            $update($device);
            $device->generation++;
            $device->lock_version++;
            $device->probe_id = $device->probe_expires_at = $device->probe_confirmed_at = null;
            $device->save();
            SmsMessage::query()->where('device_id', $device->id)->whereIn('status', ['queued', 'claimed'])
                ->update(['status' => 'cancelled', 'completed_at' => now()]);
            SmsMessage::query()->where('device_id', $device->id)->where('status', 'submitting')
                ->update(['status' => 'uncertain', 'completed_at' => now()]);

            return $device;
        });
    }
}
