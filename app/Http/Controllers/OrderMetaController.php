<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderCurrencyException;
use App\Http\Controllers\Concerns\NormalizesDecimalInput;
use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Services\OrderTotalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Rules\InvoiceFinancialStorageRule;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class OrderMetaController extends Controller
{
    use NormalizesDecimalInput;
    use RespondsToOrderAjax;

    public function updatePaidAmount(
        Request $request,
        Order $order,
        InvoiceDecimalCalculator $decimal,
    ): JsonResponse|RedirectResponse {
        $this->normalizeDecimalFields($request, ['paid_amount']);
        $maximum = $decimal->max((string) ($order->total_gross ?? '0'), '0.00');

        $validated = $request->validate([
            'paid_amount' => [
                'bail',
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::ORDER_MONEY,
                    'Kwota zapłacona przekracza maksymalną obsługiwaną wartość.',
                ),
                function (string $attribute, mixed $value, \Closure $fail) use ($decimal, $maximum): void {
                    if ($decimal->compare((string) $value, $maximum) > 0) {
                        $fail('Kwota zapłacona nie może przekraczać wartości zamówienia.');
                    }
                },
            ],
        ]);

        $order->update([
            'paid_amount' => $validated['paid_amount'],
        ]);

        $order->events()->create([
            'event_type' => 'paid_amount_updated',
            'title' => html_entity_decode('Wp&#322;ata zaktualizowana', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Zaktualizowano kwot&#281; wp&#322;aty', ENT_QUOTES, 'UTF-8'),
            'payload' => [
                'paid_amount' => $order->paid_amount,
            ],
        ]);

        return $this->orderMutationResponse($request, ['order-info', 'history'], back());
    }

    public function recalculateTotal(Request $request, Order $order, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        try {
            $totalGross = $orderTotalService->recalculate($order);
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        } catch (InvoiceDomainException $exception) {
            throw ValidationException::withMessages(['total_gross' => $exception->getMessage()]);
        }

        $order->events()->create([
            'event_type' => 'order_total_recalculated',
            'title' => html_entity_decode('Warto&#347;&#263; zam&oacute;wienia przeliczona', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Przeliczono warto&#347;&#263; zam&oacute;wienia na podstawie produkt&oacute;w i kosztu wysy&#322;ki', ENT_QUOTES, 'UTF-8'),
            'payload' => [
                'total_gross' => $order->total_gross,
            ],
        ]);

        return $this->orderMutationResponse(
            $request,
            ['order-info', 'history'],
            back()->with('success', html_entity_decode('Warto&#347;&#263; zam&oacute;wienia zosta&#322;a przeliczona.', ENT_QUOTES, 'UTF-8'))
        );
    }

    public function updatePickupPoint(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'pickup_point_name' => ['nullable', 'string', 'max:255'],
            'pickup_point_id' => ['nullable', 'string', 'max:255'],
            'pickup_point_address' => ['nullable', 'string', 'max:255'],
            'pickup_point_postal_code' => ['nullable', 'string', 'max:255'],
            'pickup_point_city' => ['nullable', 'string', 'max:255'],
        ]);

        $order->update($validated);

        $order->events()->create([
            'event_type' => 'pickup_point_updated',
            'title' => html_entity_decode('Odbi&oacute;r w punkcie zaktualizowany', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Zaktualizowano dane punktu odbioru', ENT_QUOTES, 'UTF-8'),
            'payload' => $validated,
        ]);

        return $this->orderMutationResponse($request, ['pickup', 'history'], back());
    }

    public function updateStarColor(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'star_color' => ['nullable', Rule::in(['orange', 'navy', 'green', 'blue', 'red'])],
        ]);

        $order->update([
            'star_color' => $validated['star_color'] ?? null,
        ]);

        return $this->orderMutationResponse($request, [], back());
    }
}
