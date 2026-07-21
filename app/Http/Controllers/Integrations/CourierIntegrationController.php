<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\NormalizesDecimalInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Integrations\AllegroShipping\Exceptions\AllegroShippingApiException;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingClient;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingDeviceAuthService;
use Modules\Integrations\DPD\Exceptions\DpdApiException;
use Modules\Integrations\DPD\Services\DpdClient;
use Modules\Integrations\DPD\Services\DpdServiceResolver;
use Modules\Integrations\InPost\Exceptions\InPostApiException;
use Modules\Integrations\InPost\Services\InPostClient;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Services\AllegroShippingAccountService;
use Modules\Shipments\Services\DpdAccountService;
use Modules\Shipments\Services\InPostAccountService;
use Modules\Shipments\Services\InPostCourierAccountService;
use Modules\Shipments\Services\InPostCourierParcelTemplateService;
use Modules\Shipments\Services\InPostPanelService;

class CourierIntegrationController extends Controller
{
    use NormalizesDecimalInput;

    public function index(): View
    {
        return view('integrations.couriers.index', [
            'inpostAccount' => CourierAccount::query()
                ->where('provider', CourierAccount::PROVIDER_INPOST_LOCKERS)
                ->first(),
            'inpostCourierAccount' => CourierAccount::query()
                ->where('provider', CourierAccount::PROVIDER_INPOST_COURIER)
                ->first(),
            'dpdAccount' => CourierAccount::query()
                ->where('provider', CourierAccount::PROVIDER_DPD)
                ->first(),
            'allegroShippingAccount' => CourierAccount::query()
                ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
                ->first(),
        ]);
    }

    public function editInPostLockers(
        Request $request,
        InPostAccountService $accountService,
        InPostPanelService $panelService,
    ): View {
        $account = $accountService->get();
        $filters = $request->only([
            'tracking_number',
            'status',
            'service',
            'order_id',
            'cod',
            'has_errors',
            'sending_method',
            'created_from',
            'created_to',
            'status_from',
            'status_to',
        ]);
        $requestedPerPage = $request->integer('per_page', 20);
        $perPage = in_array($requestedPerPage, InPostPanelService::PER_PAGE_OPTIONS, true)
            ? $requestedPerPage
            : 20;

        return view('integrations.couriers.inpost-lockers', [
            'account' => $accountService->get(),
            'filters' => $filters,
            'shipments' => $panelService->shipments($filters, $perPage),
            'shipmentStatuses' => $panelService->statusOptions(),
            'perPageOptions' => InPostPanelService::PER_PAGE_OPTIONS,
            'perPage' => $perPage,
        ]);
    }

    public function updateInPostLockers(Request $request, InPostAccountService $accountService): RedirectResponse
    {
        $account = $accountService->get();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'api_token' => ['nullable', 'string', 'min:10'],
            'organization_id' => ['required', 'string', 'max:64'],
            'default_parcel_template' => ['required', 'in:small,medium,large'],
            'label_format' => ['required', 'in:Pdf,Zpl,Epl'],
            'label_type' => ['required', 'in:A6,normal'],
            'content_description_source' => ['required', 'in:order_id,customer_login,customer_email,customer_phone'],
            'sending_method' => ['required', 'in:parcel_locker,dispatch_order'],
            'sender_point_id' => ['nullable', 'required_if:sending_method,parcel_locker', 'string', 'max:32'],
            'sender_company_name' => ['required', 'string', 'max:255'],
            'sender_contact_name' => ['required', 'string', 'max:255', 'regex:/^\\S+\\s+\\S+/u'],
            'sender_street' => ['required', 'string', 'max:255'],
            'sender_building_number' => ['required', 'string', 'max:32'],
            'sender_apartment_number' => ['nullable', 'string', 'max:32'],
            'sender_postal_code' => ['required', 'string', 'max:16'],
            'sender_city' => ['required', 'string', 'max:255'],
            'sender_country_code' => ['required', 'string', 'size:2'],
            'sender_phone' => ['required', 'string', 'max:50'],
            'sender_email' => ['required', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (blank($validated['api_token'] ?? null) && blank($account->resolvedApiToken())) {
            throw ValidationException::withMessages([
                'api_token' => 'Wpisz token API InPost.',
            ]);
        }

        $accountService->save($validated);

        return back()->with('success', 'Ustawienia InPost Paczkomaty zostaly zapisane.');
    }

    public function testInPostLockers(InPostClient $client): RedirectResponse
    {
        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_INPOST_LOCKERS)
            ->firstOrFail();

        try {
            $organization = $client->testConnection($account);

            $account->update([
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            return back()->with(
                'inpost_connection_success',
                'Polaczenie z InPost dziala. Organizacja: '.($organization['name'] ?? $account->organization_id).'.',
            );
        } catch (InPostApiException $exception) {
            $account->update([
                'last_tested_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            return back()
                ->withErrors(['inpost_connection' => $exception->getMessage()])
                ->with('inpost_connection_tested', true);
        }
    }

    public function editInPostCourier(
        Request $request,
        InPostCourierAccountService $accountService,
        InPostCourierParcelTemplateService $parcelTemplates,
        InPostCourierServiceResolver $serviceResolver,
        InPostPanelService $panelService,
    ): View {
        $account = $accountService->get();
        $filters = $request->only([
            'tracking_number',
            'status',
            'service',
            'order_id',
            'cod',
            'has_errors',
            'created_from',
            'created_to',
            'status_from',
            'status_to',
        ]);
        $requestedPerPage = $request->integer('per_page', 20);
        $perPage = in_array($requestedPerPage, InPostPanelService::PER_PAGE_OPTIONS, true)
            ? $requestedPerPage
            : 20;

        return view('integrations.couriers.inpost-courier', [
            'account' => $account,
            'parcelTemplates' => $parcelTemplates->all($account),
            'filters' => $filters,
            'shipments' => $panelService->shipments(
                $filters,
                $perPage,
                CourierAccount::PROVIDER_INPOST_COURIER,
            ),
            'shipmentStatuses' => $panelService->statusOptions(),
            'serviceLabels' => $serviceResolver->serviceLabels(),
            'perPageOptions' => InPostPanelService::PER_PAGE_OPTIONS,
            'perPage' => $perPage,
        ]);
    }

    public function updateInPostCourier(
        Request $request,
        InPostCourierAccountService $accountService,
        InPostCourierServiceResolver $serviceResolver,
    ): RedirectResponse {
        $account = $accountService->get();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'api_token' => ['nullable', 'string', 'min:10'],
            'organization_id' => ['required', 'string', 'max:64'],
            'default_service' => ['required', 'in:'.implode(',', $serviceResolver->supportedServices())],
            'default_weight' => ['required', 'numeric', 'gt:0', 'max:50'],
            'default_length' => ['required', 'numeric', 'gt:0', 'max:350'],
            'default_width' => ['required', 'numeric', 'gt:0', 'max:350'],
            'default_height' => ['required', 'numeric', 'gt:0', 'max:350'],
            'default_insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'label_format' => ['required', 'in:Pdf,Zpl,Epl'],
            'label_type' => ['required', 'in:A6,normal'],
            'content_description_source' => ['required', 'in:order_id,customer_login,customer_email,customer_phone'],
            'default_sms' => ['nullable', 'boolean'],
            'default_email' => ['nullable', 'boolean'],
            'default_saturday' => ['nullable', 'boolean'],
            'default_return_documents' => ['nullable', 'boolean'],
            'sender_company_name' => ['required', 'string', 'max:255'],
            'sender_contact_name' => ['required', 'string', 'max:255', 'regex:/^\S+\s+\S+/u'],
            'sender_street' => ['required', 'string', 'max:255'],
            'sender_building_number' => ['required', 'string', 'max:32'],
            'sender_apartment_number' => ['nullable', 'string', 'max:32'],
            'sender_postal_code' => ['required', 'string', 'max:16'],
            'sender_city' => ['required', 'string', 'max:255'],
            'sender_country_code' => ['required', 'string', 'size:2'],
            'sender_phone' => ['required', 'string', 'max:50'],
            'sender_email' => ['required', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'sender_contact_name.regex' => 'Podaj imie i nazwisko osoby kontaktowej.',
            'default_weight.max' => 'Domyslna waga paczki nie moze przekraczac 50 kg.',
        ]);

        if (blank($validated['api_token'] ?? null) && blank($account->resolvedApiToken())) {
            throw ValidationException::withMessages([
                'api_token' => 'Wpisz token API InPost.',
            ]);
        }

        $accountService->save($validated);

        return back()->with('success', 'Ustawienia InPost Kurier zostaly zapisane.');
    }

    public function testInPostCourier(InPostClient $client): RedirectResponse
    {
        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_INPOST_COURIER)
            ->firstOrFail();

        try {
            $organization = $client->testConnection($account);

            $account->update([
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            return back()->with(
                'inpost_courier_connection_success',
                'Polaczenie z InPost dziala. Organizacja: '.($organization['name'] ?? $account->organization_id).'.',
            );
        } catch (InPostApiException $exception) {
            $account->update([
                'last_tested_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            return back()
                ->withErrors(['inpost_courier_connection' => $exception->getMessage()])
                ->with('inpost_courier_connection_tested', true);
        }
    }

    public function editDpd(
        Request $request,
        DpdAccountService $accountService,
        InPostCourierParcelTemplateService $parcelTemplates,
        DpdServiceResolver $serviceResolver,
        InPostPanelService $panelService,
    ): View {
        $account = $accountService->get();
        $filters = $request->only([
            'tracking_number', 'status', 'service', 'order_id', 'cod', 'has_errors',
            'created_from', 'created_to', 'status_from', 'status_to',
        ]);
        $requestedPerPage = $request->integer('per_page', 20);
        $perPage = in_array($requestedPerPage, InPostPanelService::PER_PAGE_OPTIONS, true)
            ? $requestedPerPage
            : 20;

        return view('integrations.couriers.dpd', [
            'account' => $account,
            'parcelTemplates' => $parcelTemplates->all($account),
            'filters' => $filters,
            'shipments' => $panelService->shipments($filters, $perPage, CourierAccount::PROVIDER_DPD),
            'shipmentStatuses' => $panelService->statusOptions(),
            'serviceLabels' => $serviceResolver->serviceLabels(),
            'perPageOptions' => InPostPanelService::PER_PAGE_OPTIONS,
            'perPage' => $perPage,
        ]);
    }

    public function updateDpd(
        Request $request,
        DpdAccountService $accountService,
        DpdServiceResolver $serviceResolver,
    ): RedirectResponse {
        $account = $accountService->get();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'api_login' => ['required', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'min:4'],
            'organization_id' => ['required', 'string', 'max:64'],
            'info_channel' => ['required', 'string', 'max:255'],
            'default_service' => ['required', 'in:'.implode(',', $serviceResolver->supportedServices())],
            'default_weight' => ['required', 'numeric', 'gt:0', 'max:700'],
            'default_length' => ['required', 'numeric', 'gt:0', 'max:300'],
            'default_width' => ['required', 'numeric', 'gt:0', 'max:300'],
            'default_height' => ['required', 'numeric', 'gt:0', 'max:300'],
            'default_insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'label_format' => ['required', 'in:PDF,ZPL,EPL'],
            'label_type' => ['required', 'in:A4,LABEL'],
            'content_description_source' => ['required', 'in:order_id,customer_login,customer_email,customer_phone'],
            'default_saturday' => ['nullable', 'boolean'],
            'default_return_documents' => ['nullable', 'boolean'],
            'sender_company_name' => ['required', 'string', 'max:255'],
            'sender_contact_name' => ['required', 'string', 'max:255'],
            'sender_street' => ['required', 'string', 'max:255'],
            'sender_building_number' => ['required', 'string', 'max:32'],
            'sender_apartment_number' => ['nullable', 'string', 'max:32'],
            'sender_postal_code' => ['required', 'string', 'max:16'],
            'sender_city' => ['required', 'string', 'max:255'],
            'sender_country_code' => ['required', 'string', 'size:2'],
            'sender_phone' => ['required', 'string', 'max:50'],
            'sender_email' => ['required', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (blank($validated['api_token'] ?? null) && blank($account->resolvedApiToken())) {
            throw ValidationException::withMessages(['api_token' => 'Wpisz haslo API DPD.']);
        }

        $accountService->save($validated);

        return back()->with('success', 'Ustawienia DPD zostaly zapisane.');
    }

    public function testDpd(DpdClient $client): RedirectResponse
    {
        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_DPD)
            ->firstOrFail();

        try {
            $client->testConnection($account, (string) $account->setting('sender_postal_code'));
            $account->update(['last_tested_at' => now(), 'last_error' => null]);

            return back()->with('dpd_connection_success', 'Polaczenie z DPD dziala poprawnie.');
        } catch (DpdApiException $exception) {
            $account->update(['last_tested_at' => now(), 'last_error' => $exception->getMessage()]);

            return back()
                ->withErrors(['dpd_connection' => $exception->getMessage()])
                ->with('dpd_connection_tested', true);
        }
    }

    public function editAllegroShipping(
        Request $request,
        AllegroShippingAccountService $accountService,
        InPostPanelService $panelService,
        InPostCourierParcelTemplateService $parcelTemplates,
    ): View {
        $account = $accountService->get();
        $filters = $request->only([
            'tracking_number', 'status', 'order_id', 'cod', 'has_errors',
            'created_from', 'created_to', 'status_from', 'status_to',
        ]);
        $requestedPerPage = $request->integer('per_page', 20);
        $perPage = in_array($requestedPerPage, InPostPanelService::PER_PAGE_OPTIONS, true) ? $requestedPerPage : 20;

        return view('integrations.couriers.allegro-shipping', [
            'account' => $account,
            'filters' => $filters,
            'shipments' => $panelService->shipments($filters, $perPage, CourierAccount::PROVIDER_ALLEGRO_SHIPPING),
            'shipmentStatuses' => $panelService->statusOptions(),
            'perPageOptions' => InPostPanelService::PER_PAGE_OPTIONS,
            'perPage' => $perPage,
            'parcelTemplates' => $parcelTemplates->all($account),
        ]);
    }

    public function updateAllegroShipping(Request $request, AllegroShippingAccountService $accountService): RedirectResponse
    {
        $validated = $this->validateAllegroShippingAccount($request, $accountService);
        $account = $accountService->save($validated);

        $message = $account->hasCompleteCredentials()
            ? 'Ustawienia Wysylam z Allegro zostaly zapisane.'
            : 'Ustawienia zapisano. Teraz polacz konto przez Device Flow Allegro.';

        return back()->with('success', $message);
    }

    public function startAllegroShippingDevice(
        Request $request,
        AllegroShippingAccountService $accountService,
        AllegroShippingDeviceAuthService $deviceAuth,
    ): RedirectResponse {
        $validated = $this->validateAllegroShippingAccount($request, $accountService);
        $account = $accountService->save($validated);

        try {
            $authorization = $deviceAuth->start($account);
            $request->session()->put('allegro_device_authorization', $authorization);

            return back()->with('allegro_device_started', true);
        } catch (AllegroShippingApiException $exception) {
            return back()
                ->withErrors(['allegro_device' => $exception->getMessage()])
                ->withInput();
        }
    }

    public function pollAllegroShippingDevice(
        Request $request,
        AllegroShippingDeviceAuthService $deviceAuth,
    ): JsonResponse {
        $authorization = $request->session()->get('allegro_device_authorization');

        if (! is_array($authorization) || blank($authorization['device_code'] ?? null)) {
            return response()->json(['status' => 'expired', 'message' => 'Brak aktywnego laczenia z Allegro.'], 410);
        }
        if ((int) ($authorization['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('allegro_device_authorization');

            return response()->json(['status' => 'expired', 'message' => 'Kod laczenia z Allegro wygasl.'], 410);
        }

        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->findOrFail((int) $authorization['account_id']);

        try {
            $result = $deviceAuth->poll($account, (string) $authorization['device_code']);
            if (($result['slow_down'] ?? false) === true) {
                $authorization['interval'] = (int) ($authorization['interval'] ?? 5) + 5;
                $request->session()->put('allegro_device_authorization', $authorization);
            }
            if (($result['status'] ?? null) === 'connected') {
                $request->session()->forget('allegro_device_authorization');
            }

            return response()->json($result + ['interval' => (int) ($authorization['interval'] ?? 5)]);
        } catch (AllegroShippingApiException $exception) {
            if ($exception->statusCode === 400) {
                $request->session()->forget('allegro_device_authorization');
            }

            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
    }

    public function cancelAllegroShippingDevice(Request $request): RedirectResponse
    {
        $request->session()->forget('allegro_device_authorization');

        return back();
    }

    private function validateAllegroShippingAccount(
        Request $request,
        AllegroShippingAccountService $accountService,
    ): array {
        $this->normalizeDecimalFields($request, [
            'default_weight',
            'default_length',
            'default_width',
            'default_height',
        ]);

        $account = $accountService->get();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'organization_id' => ['required', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'min:8'],
              'label_format' => ['required', 'in:PDF,ZPL'],
              'label_type' => ['required', 'in:A4,A6'],
              'content_description_source' => ['required', 'in:order_id,external_id,customer_login,customer_email,customer_phone'],
              'reference_number_source' => ['required', 'in:order_id,external_id,customer_login,customer_email,customer_phone'],
              'default_weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'default_length' => ['required', 'numeric', 'gt:0', 'max:500'],
            'default_width' => ['required', 'numeric', 'gt:0', 'max:500'],
            'default_height' => ['required', 'numeric', 'gt:0', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (blank($validated['api_secret'] ?? null) && blank($account->resolvedApiSecret())) {
            throw ValidationException::withMessages(['api_secret' => 'Wpisz Client Secret aplikacji Device Allegro.']);
        }

        return $validated;
    }

    public function testAllegroShipping(AllegroShippingClient $client): RedirectResponse
    {
        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->firstOrFail();

        try {
            $services = $client->testConnection($account);
            $account->update(['last_tested_at' => now(), 'last_error' => null]);

            return back()->with(
                'allegro_shipping_connection_success',
                'Polaczenie z Allegro dziala. Dostepne uslugi: '.count((array) ($services['services'] ?? [])).'.',
            );
        } catch (AllegroShippingApiException $exception) {
            $account->update(['last_tested_at' => now(), 'last_error' => $exception->getMessage()]);

            return back()
                ->withErrors(['allegro_shipping_connection' => $exception->getMessage()])
                ->with('allegro_shipping_connection_tested', true);
        }
    }
}
