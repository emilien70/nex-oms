<?php

namespace Modules\CustomerPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Models\PortalStatus;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\CustomerPortal\Services\PortalPaymentService;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\CustomerPortal\Services\PortalVerificationService;

class PortalSettingsController extends Controller
{
    public function edit(Request $request, PortalAccessService $access, PortalPaymentService $payments)
    {
        $data = $request->validate(['order' => ['sometimes', 'integer', 'min:1']]);
        $order = isset($data['order']) ? Order::query()->findOrFail($data['order']) : null;

        return view('settings.customer-portal.edit', [
            'ready' => $access->ready(), 'settings' => $access->settings(),
            'paymentsReady' => $payments->ready(),
            'privacyReady' => app(PortalVerificationService::class)->ready(),
            'portalBaseUrl' => app(PortalUrlService::class)->base(),
            'managedPortalOrder' => $order,
            'managedPortalLink' => $order && $access->ready() ? PortalLink::query()->where('order_id', $order->id)->first() : null,
            'managedPortalData' => $order ? $access->orderLink($order) : null,
            'statuses' => OrderStatusSetting::query()->orderBy('sort_order')->orderBy('id')->get(),
            'mappings' => $access->ready() ? PortalStatus::query()->get()->keyBy('order_status_setting_id') : collect(),
        ]);
    }

    public function update(Request $request, PortalAccessService $access, PortalPaymentService $payments)
    {
        abort_unless($access->ready(), 503);
        abort_if($request->has('paynow_settings_present') && ! $payments->ready(), 503);
        abort_if($request->has('privacy_settings_present') && ! app(PortalVerificationService::class)->ready(), 503);
        if (is_string($request->input('customer_domain'))) {
            $request->merge(['customer_domain' => strtolower(trim($request->input('customer_domain'))) ?: null]);
        }
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'is_enabled' => ['required', 'boolean'], 'store_name' => ['required', 'string', 'max:120'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'availability_days' => ['required', 'integer', 'between:1,365'],
            'contact_email' => ['nullable', 'email', 'max:254'], 'contact_phone' => ['nullable', 'string', 'regex:/^[0-9 +().-]{3,60}$/'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
            'show_images' => ['required', 'boolean'], 'show_address' => ['required', 'boolean'],
            'show_invoices' => ['required', 'boolean'], 'show_corrections' => ['required', 'boolean'], 'show_proformas' => ['required', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2400,max_height=2400'],
            'remove_logo' => ['sometimes', 'boolean'], 'statuses' => ['sometimes', 'array', 'max:200'],
            'statuses.*.id' => ['required', 'integer', 'distinct'],
            'statuses.*.is_visible' => ['required', 'boolean'],
            'statuses.*.public_name' => ['nullable', 'string', 'max:120'],
            'statuses.*.public_message' => ['nullable', 'string', 'max:1000'],
            'paynow_settings_present' => ['sometimes', 'accepted'],
            'paynow_enabled' => ['required_with:paynow_settings_present', 'boolean'],
            'paynow_retry_enabled' => ['required_with:paynow_settings_present', 'boolean'],
            'paynow_sources' => ['sometimes', 'array', 'max:2'],
            'paynow_sources.*' => ['required', 'string', 'distinct', 'in:manual,prestashop'],
            'privacy_settings_present' => ['sometimes', 'accepted'],
            'customer_domain' => ['bail', 'nullable', 'string', 'max:253', function ($attribute, $value, $fail): void {
                if (! PortalUrlService::validDomain($value)) {
                    $fail('Wpisz samą domenę, bez protokołu, portu i ścieżki.');
                } elseif ($value === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))) {
                    $fail('Domena klienta musi być inna niż domena panelu OMS.');
                }
            }],
            'mask_personal_data' => ['required_with:privacy_settings_present', 'boolean'],
            'verify_documents' => ['required_with:privacy_settings_present', 'boolean'],
            'verification_method' => ['required_with:privacy_settings_present', 'string', 'in:any,email,name_company'],
        ]);
        $uploaded = $request->file('logo')?->store('customer-portal/logos', 'local');
        try {
            DB::transaction(function () use ($data, $request, $uploaded): void {
                $settings = PortalSetting::query()->where('singleton_key', PortalSetting::KEY)->lockForUpdate()->first();
                if ((int) $data['expected_revision'] !== ($settings?->revision ?? 0)) {
                    throw ValidationException::withMessages(['expected_revision' => 'Ustawienia zostały zmienione. Odśwież stronę przed ponownym zapisem.']);
                }
                $oldLogo = $settings?->logo_path;
                $settings ??= new PortalSetting;
                foreach (['is_enabled', 'store_name', 'accent_color', 'availability_days', 'contact_email', 'contact_phone',
                    'customer_message', 'show_images', 'show_address', 'show_invoices', 'show_corrections', 'show_proformas'] as $field) {
                    $settings->{$field} = $data[$field] ?? null;
                }
                $settings->revision = ($settings->exists ? $settings->revision : 0) + 1;
                if (isset($data['paynow_settings_present'])) {
                    $settings->fill([
                        'paynow_enabled' => $data['paynow_enabled'],
                        'paynow_retry_enabled' => $data['paynow_retry_enabled'],
                        'paynow_sources' => $data['paynow_sources'] ?? [],
                    ]);
                }
                if (isset($data['privacy_settings_present'])) {
                    $settings->fill([
                        'customer_domain' => $data['customer_domain'] ?? null,
                        'mask_personal_data' => $data['mask_personal_data'],
                        'verify_documents' => $data['verify_documents'],
                        'verification_method' => $data['verification_method'],
                    ]);
                }
                if ($uploaded || $request->boolean('remove_logo')) {
                    $settings->logo_path = $uploaded;
                }
                $settings->save();
                foreach ($data['statuses'] ?? [] as $row) {
                    if (! OrderStatusSetting::query()->whereKey($row['id'])->exists()
                        || ($row['is_visible'] && blank($row['public_name'] ?? null))) {
                        throw ValidationException::withMessages(['statuses' => 'Sprawdź statusy i uzupełnij nazwy widoczne dla klienta.']);
                    }
                    PortalStatus::query()->updateOrCreate(['order_status_setting_id' => $row['id']], [
                        'is_visible' => (bool) $row['is_visible'], 'public_name' => $row['public_name'] ?? null,
                        'public_message' => $row['public_message'] ?? null,
                    ]);
                }
                if ($oldLogo && $settings->logo_path !== $oldLogo && str_starts_with($oldLogo, 'customer-portal/')) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($oldLogo));
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($uploaded) {
                Storage::disk('local')->delete($uploaded);
            }
            throw $exception;
        }

        return redirect()->route('customer-portal.settings')->with('success', 'Zapisano ustawienia strony klienta.');
    }

    public function selectPreview(Request $request)
    {
        $data = $request->validate(['order' => ['required', 'integer', 'min:1']]);
        $order = Order::query()->findOrFail($data['order']);

        return redirect()->route('customer-portal.preview', $order);
    }

    public function issue(Request $request, Order $order, PortalAccessService $access)
    {
        $request->validate(['rotate' => ['sometimes', 'declined'], 'activate' => ['sometimes', 'boolean'], 'confirm' => ['required', 'accepted'],
            'expected_generation' => ['required_if:activate,1', 'nullable', 'integer', 'min:0']]);
        $url = $request->boolean('activate')
            ? $access->activate($order, $request->integer('expected_generation'))
            : $access->issue($order);

        return $request->expectsJson()
            ? response()->json(['url' => $url, 'generation' => PortalLink::query()->where('order_id', $order->id)->value('generation')])->header('Cache-Control', 'private, no-store')
            : redirect()->route('customer-portal.settings', ['order' => $order->id])->with('success', 'Włączono dostęp do strony zamówienia. Adres pozostaje bez zmian.');
    }

    public function revoke(Request $request, Order $order, PortalAccessService $access)
    {
        $request->validate(['confirm' => ['required', 'accepted'], 'expected_generation' => ['required', 'integer', 'min:1']]);
        $access->revoke($order, $request->integer('expected_generation'));

        return redirect()->route('customer-portal.settings', ['order' => $order->id])->with('success', 'Wyłączono link strony klienta.');
    }
}
