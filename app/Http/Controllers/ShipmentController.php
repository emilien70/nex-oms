<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Http\Controllers\Concerns\NormalizesDecimalInput;
use App\Models\Order;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Integrations\DPD\Services\DpdServiceResolver;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingProposalService;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Integrations\InPost\Services\InPostShipmentServiceResolver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Modules\Shipments\Services\CourierDriverRegistry;
use Modules\Shipments\Services\ShipmentCancellationService;
use Modules\Shipments\Services\ShipmentFormViewService;
use Modules\Shipments\Services\ShipmentRetryService;
use Symfony\Component\HttpFoundation\Response;

class ShipmentController extends Controller
{
    use NormalizesDecimalInput;
    use RespondsToOrderAjax;

    public function form(
        Order $order,
        string $provider,
        ShipmentFormViewService $forms,
    ): JsonResponse {
        $account = CourierAccount::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json([
                'message' => 'Wybrana integracja kurierska nie jest aktywna.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $order->refresh();
            $form = $forms->make($order, $account);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'provider' => $provider,
            ...$form,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function storeInPost(
        Request $request,
        Order $order,
        CourierDriverRegistry $drivers,
        InPostShipmentServiceResolver $serviceResolver,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'service' => ['nullable', 'string', Rule::in($serviceResolver->supportedServices())],
            'parcel_template' => ['required', 'in:small,medium,large'],
            'target_point_id' => ['required', 'string', 'max:64'],
            'sending_method' => ['required', 'in:parcel_locker,dispatch_order'],
            'content_description' => ['nullable', 'string', 'max:100'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*' => ['string', Rule::in([
                Shipment::ADDITIONAL_SERVICE_WEEKEND,
                Shipment::ADDITIONAL_SERVICE_RETURN_LABEL,
            ])],
        ]);

        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_INPOST_LOCKERS)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Najpierw skonfiguruj i aktywuj konto InPost Paczkomaty.',
                    'errors' => ['shipment' => ['Najpierw skonfiguruj i aktywuj konto InPost Paczkomaty.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => 'Najpierw skonfiguruj i aktywuj konto InPost Paczkomaty.']);
        }

        try {
            $order->refresh();
            $attempt = $drivers->forAccount($account)->queueShipment($order, $account, $validated);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['shipment' => [$exception->getMessage()]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(
                $this->shipmentCreationAttemptPayload($attempt),
                Response::HTTP_ACCEPTED,
            );
        }

        return back()->with('success', 'Przesylka InPost zostala dodana do kolejki.');
    }

    public function storeInPostCourier(
        Request $request,
        Order $order,
        CourierDriverRegistry $drivers,
        InPostCourierServiceResolver $serviceResolver,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in($serviceResolver->supportedServices())],
            'content_description' => ['nullable', 'string', 'max:100'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'gte:cod_amount'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*' => ['string', Rule::in([
                Shipment::ADDITIONAL_SERVICE_SMS,
                Shipment::ADDITIONAL_SERVICE_EMAIL,
                Shipment::ADDITIONAL_SERVICE_SATURDAY,
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
            ])],
            'parcels' => ['required', 'array', 'min:1', 'max:99'],
            'parcels.*.weight' => ['required', 'numeric', 'gt:0', 'max:50'],
            'parcels.*.length' => ['required', 'numeric', 'gt:0', 'max:350'],
            'parcels.*.width' => ['required', 'numeric', 'gt:0', 'max:350'],
            'parcels.*.height' => ['required', 'numeric', 'gt:0', 'max:350'],
            'parcels.*.is_non_standard' => ['nullable', 'boolean'],
        ], [
            'insurance_amount.gte' => 'Ubezpieczenie musi byc rowne lub wyzsze od kwoty pobrania.',
            'parcels.required' => 'Dodaj co najmniej jedna paczke.',
            'parcels.min' => 'Dodaj co najmniej jedna paczke.',
            'parcels.*.weight.gt' => 'Waga paczki musi byc wieksza od zera.',
            'parcels.*.weight.max' => 'Waga jednej paczki nie moze przekraczac 50 kg.',
        ]);

        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_INPOST_COURIER)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            $message = 'Najpierw skonfiguruj i aktywuj konto InPost Kurier.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['shipment' => [$message]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $message]);
        }

        try {
            $order->refresh();
            $attempt = $drivers->forAccount($account)->queueShipment($order, $account, $validated);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['shipment' => [$exception->getMessage()]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(
                $this->shipmentCreationAttemptPayload($attempt),
                Response::HTTP_ACCEPTED,
            );
        }

        return back()->with('success', 'Przesylka InPost Kurier zostala dodana do kolejki.');
    }

    public function storeDpd(
        Request $request,
        Order $order,
        CourierDriverRegistry $drivers,
        DpdServiceResolver $serviceResolver,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in($serviceResolver->supportedServices())],
            'content_description' => ['nullable', 'string', 'max:100'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*' => ['string', Rule::in([
                Shipment::ADDITIONAL_SERVICE_SATURDAY,
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
            ])],
            'parcels' => ['required', 'array', 'min:1', 'max:100'],
            'parcels.*.weight' => ['required', 'numeric', 'gt:0', 'max:700'],
            'parcels.*.length' => ['required', 'numeric', 'gt:0', 'max:300'],
            'parcels.*.width' => ['required', 'numeric', 'gt:0', 'max:300'],
            'parcels.*.height' => ['required', 'numeric', 'gt:0', 'max:300'],
        ], [
            'parcels.required' => 'Dodaj co najmniej jedna paczke.',
            'parcels.min' => 'Dodaj co najmniej jedna paczke.',
            'parcels.*.weight.gt' => 'Waga paczki musi byc wieksza od zera.',
        ]);

        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_DPD)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            $message = 'Najpierw skonfiguruj i aktywuj konto DPD.';

            return $request->expectsJson()
                ? response()->json([
                    'message' => $message,
                    'errors' => ['shipment' => [$message]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY)
                : back()->withErrors(['shipment' => $message]);
        }

        try {
            $order->refresh();
            $attempt = $drivers->forAccount($account)->queueShipment($order, $account, $validated);
        } catch (DomainException $exception) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['shipment' => [$exception->getMessage()]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY)
                : back()->withErrors(['shipment' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(
                $this->shipmentCreationAttemptPayload($attempt),
                Response::HTTP_ACCEPTED,
            );
        }

        return back()->with('success', 'Przesylka DPD zostala dodana do kolejki.');
    }

    public function storeAllegroShipping(
        Request $request,
        Order $order,
        CourierDriverRegistry $drivers,
        AllegroShippingProposalService $proposals,
    ): RedirectResponse|JsonResponse {
        $this->normalizeDecimalFields($request, ['cod_amount', 'insurance_amount']);
        $this->normalizeNestedDecimalFields($request, 'parcels', ['weight', 'length', 'width', 'height']);

        $validated = $request->validate([
            'content_description' => ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'package_type' => ['required', 'string', 'max:30'],
            'swap_sender_receiver' => ['nullable', 'boolean'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'label_format' => ['required', 'in:PDF,ZPL'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*' => ['string', 'max:100'],
            'parcels' => ['required', 'array', 'min:1', 'max:10'],
            'parcels.*.weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'parcels.*.length' => ['required', 'numeric', 'gt:0', 'max:500'],
            'parcels.*.width' => ['required', 'numeric', 'gt:0', 'max:500'],
            'parcels.*.height' => ['required', 'numeric', 'gt:0', 'max:500'],
        ]);

        $account = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->where('is_active', true)
            ->first();
        $message = 'Najpierw skonfiguruj i aktywuj konto Wysylam z Allegro.';

        if (! $account) {
            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['shipment' => [$message]]], Response::HTTP_UNPROCESSABLE_ENTITY)
                : back()->withErrors(['shipment' => $message]);
        }

        try {
            $order->refresh();
            $proposal = $proposals->formData($order, $account);
            $allowedServices = array_keys((array) ($proposal['available_additional_services'] ?? []));
            $requestedServices = array_values($validated['additional_services'] ?? []);
            if (array_diff($requestedServices, $allowedServices) !== []) {
                throw new DomainException('Wybrano usluge dodatkowa, ktorej Allegro nie udostepnia dla tego zamowienia.');
            }
            $allowedPackageTypes = array_keys((array) ($proposal['available_package_types'] ?? []));
            if (! in_array($validated['package_type'], $allowedPackageTypes, true)) {
                throw new DomainException('Wybrany rodzaj przesylki nie jest dostepny dla tego zamowienia Allegro.');
            }
            $attempt = $drivers->forAccount($account)->queueShipment($order, $account, $validated);
        } catch (DomainException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage(), 'errors' => ['shipment' => [$exception->getMessage()]]], Response::HTTP_UNPROCESSABLE_ENTITY)
                : back()->withErrors(['shipment' => $exception->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(
                $this->shipmentCreationAttemptPayload($attempt),
                Response::HTTP_ACCEPTED,
            );
        }

        return back()->with('success', 'Przesylka Wysylam z Allegro zostala dodana do kolejki.');
    }

    public function status(Shipment $shipment): JsonResponse
    {
        return response()->json(
            $this->shipmentStatusPayload($shipment->fresh()->load(['courierAccount', 'parcels'])),
        );
    }

    public function creationAttemptStatus(ShipmentCreationAttempt $shipmentCreationAttempt): JsonResponse
    {
        return response()->json($this->shipmentCreationAttemptPayload($shipmentCreationAttempt));
    }

    public function refresh(Request $request, Shipment $shipment, CourierDriverRegistry $drivers): JsonResponse|RedirectResponse
    {
        try {
            $drivers->forShipment($shipment)->dispatchRefresh($shipment);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse(
            $request,
            ['shipments', 'history'],
            back()->with('success', 'Odswiezanie przesylki zostalo zlecone.')
        );
    }

    public function bulkRefresh(Request $request, CourierDriverRegistry $drivers): RedirectResponse
    {
        return $this->bulkRefreshForProvider(
            $request,
            $drivers,
            CourierAccount::PROVIDER_INPOST_LOCKERS,
        );
    }

    public function bulkRefreshInPostCourier(Request $request, CourierDriverRegistry $drivers): RedirectResponse
    {
        return $this->bulkRefreshForProvider(
            $request,
            $drivers,
            CourierAccount::PROVIDER_INPOST_COURIER,
        );
    }

    public function bulkRefreshDpd(Request $request, CourierDriverRegistry $drivers): RedirectResponse
    {
        return $this->bulkRefreshForProvider($request, $drivers, CourierAccount::PROVIDER_DPD);
    }

    public function bulkRefreshAllegroShipping(Request $request, CourierDriverRegistry $drivers): RedirectResponse
    {
        return $this->bulkRefreshForProvider($request, $drivers, CourierAccount::PROVIDER_ALLEGRO_SHIPPING);
    }

    public function bulkDelete(Request $request, ShipmentCancellationService $cancellationService): RedirectResponse
    {
        return $this->bulkDeleteForProvider(
            $request,
            $cancellationService,
            CourierAccount::PROVIDER_INPOST_LOCKERS,
            'InPost Paczkomaty',
        );
    }

    public function bulkDeleteInPostCourier(Request $request, ShipmentCancellationService $cancellationService): RedirectResponse
    {
        return $this->bulkDeleteForProvider(
            $request,
            $cancellationService,
            CourierAccount::PROVIDER_INPOST_COURIER,
            'InPost Kurier',
        );
    }

    public function bulkDeleteDpd(Request $request, ShipmentCancellationService $cancellationService): RedirectResponse
    {
        return $this->bulkDeleteForProvider(
            $request,
            $cancellationService,
            CourierAccount::PROVIDER_DPD,
            'DPD',
        );
    }

    public function bulkDeleteAllegroShipping(Request $request, ShipmentCancellationService $cancellationService): RedirectResponse
    {
        return $this->bulkDeleteForProvider(
            $request,
            $cancellationService,
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'Wysylam z Allegro',
        );
    }

    private function bulkRefreshForProvider(
        Request $request,
        CourierDriverRegistry $drivers,
        string $provider,
    ): RedirectResponse {
        $validated = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ]);

        $shipments = Shipment::query()
            ->where('provider', $provider)
            ->whereIn('id', $validated['shipment_ids'])
            ->whereNotNull('external_id')
            ->whereNotIn('oms_status', Shipment::terminalOmsStatuses())
            ->get();

        if ($shipments->isEmpty()) {
            return back()->withErrors(['shipment' => 'Zaznaczone przesylki nie wymagaja odswiezenia albo nie maja jeszcze identyfikatora InPost.']);
        }

        $shipments->each(fn (Shipment $shipment) => $drivers->forShipment($shipment)->dispatchRefresh($shipment));

        return back()->with('success', 'Odswiezanie zaznaczonych przesylek zostalo zlecone.');
    }

    private function bulkDeleteForProvider(
        Request $request,
        ShipmentCancellationService $cancellationService,
        string $provider,
        string $providerLabel,
    ): RedirectResponse {
        $validated = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ]);

        $shipmentIds = collect($validated['shipment_ids'])->map(fn ($id): int => (int) $id)->unique();
        $shipments = Shipment::query()
            ->where('provider', $provider)
            ->whereIn('id', $shipmentIds)
            ->get();

        if ($shipments->count() !== $shipmentIds->count()) {
            return back()->withErrors(['shipment_ids' => 'Nie wszystkie zaznaczone przesylki naleza do integracji '.$providerLabel.'.']);
        }

        $shipments->each(fn (Shipment $shipment) => $cancellationService->requestWithLocalFallback($shipment));

        return back();
    }

    public function cancel(Request $request, Shipment $shipment, ShipmentCancellationService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'local_only' => ['nullable', 'boolean'],
        ]);

        try {
            $service->request($shipment, (bool) ($validated['local_only'] ?? false));
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse(
            $request,
            ['shipments', 'history'],
            back()->with('success', ! empty($validated['local_only'])
                ? 'Przesylka zostala usunieta z NEX-OMS.'
                : 'Anulowanie przesylki w integracji kurierskiej zostalo zlecone.')
        );
    }

    public function retry(Request $request, Shipment $shipment, ShipmentRetryService $service): JsonResponse|RedirectResponse
    {
        try {
            $service->retryCreation($shipment);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()->withErrors(['shipment' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse(
            $request,
            ['shipments', 'history'],
            back()->with('success', 'Ponowne nadanie przesylki zostalo dodane do kolejki.')
        );
    }

    public function label(Shipment $shipment, CourierDriverRegistry $drivers): Response|RedirectResponse
    {
        try {
            $apiResponse = $drivers->forShipment($shipment)->label($shipment);
        } catch (DomainException $exception) {
            return back()->withErrors(['shipment' => $exception->getMessage()]);
        }

        $extension = strtolower($shipment->label_format) === 'pdf' ? 'pdf' : strtolower($shipment->label_format);
        $filename = str_replace('_', '-', $shipment->provider)
            .'-'.($shipment->tracking_number ?: $shipment->id).'.'.$extension;

        return response($apiResponse->body(), 200, [
            'Content-Type' => $apiResponse->header('Content-Type') ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function shipmentStatusPayload(Shipment $shipment): array
    {
        $pollingFinished = $shipment->canDownloadLabel()
            || in_array($shipment->status, [
                Shipment::STATUS_CREATION_FAILED,
                Shipment::STATUS_CREATION_UNKNOWN,
                Shipment::STATUS_ERROR,
                Shipment::STATUS_CANCELLED,
                Shipment::STATUS_CANCELLED_LOCALLY,
                'canceled',
            ], true);

        return [
            'id' => $shipment->id,
            'status' => $shipment->oms_status,
            'status_label' => $shipment->statusLabel(),
            'provider_status' => $shipment->status,
            'tracking_number' => $shipment->tracking_number,
            'label_available' => $shipment->canDownloadLabel(),
            'polling_finished' => $pollingFinished,
            'status_url' => route('shipments.status', $shipment),
            'row_html' => view('orders.partials.shipment-row', compact('shipment'))->render(),
            'error_message' => $shipment->error_message,
        ];
    }

    private function shipmentCreationAttemptPayload(ShipmentCreationAttempt $attempt): array
    {
        $attempt = $attempt->fresh()->load(['shipment.courierAccount', 'shipment.parcels']);
        $shipment = $attempt->shipment;
        $succeeded = $attempt->status === ShipmentCreationAttempt::STATUS_SUCCEEDED
            && $shipment
            && filled($shipment->tracking_number);

        return [
            'id' => $attempt->id,
            'shipment_id' => $shipment?->id,
            'status' => $attempt->status,
            'provider_status' => $shipment?->status,
            'tracking_number' => $succeeded ? $shipment->tracking_number : null,
            'label_available' => $succeeded && $shipment->canDownloadLabel(),
            'polling_finished' => $attempt->pollingFinished(),
            'status_url' => route('shipment-creation-attempts.status', $attempt),
            'row_html' => $succeeded
                ? view('orders.partials.shipment-row', compact('shipment'))->render()
                : null,
            'error_message' => $attempt->error_message,
            'outcome_unknown' => $attempt->outcome_unknown,
        ];
    }
}
