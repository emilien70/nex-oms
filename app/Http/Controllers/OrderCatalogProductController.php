<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderCurrencyException;
use App\Exceptions\OrderPaymentStateException;
use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Http\Requests\AddCatalogProductsToOrderRequest;
use App\Models\Order;
use App\Services\OrderCatalogProductService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Products\Models\Product;

class OrderCatalogProductController extends Controller
{
    use RespondsToOrderAjax;

    public function index(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $products = Product::query()
            ->with('bundleComponents.component')
            ->where('is_active', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $nestedQuery) use ($search): void {
                    $nestedQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('ean', 'like', "%{$search}%");

                    if (ctype_digit($search)) {
                        $nestedQuery->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'html' => view('orders.partials.catalog-product-picker-results', compact('products'))->render(),
            'page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function store(
        AddCatalogProductsToOrderRequest $request,
        Order $order,
        OrderCatalogProductService $catalogProducts,
    ): JsonResponse|RedirectResponse {
        try {
            $validated = $request->validated();
            $catalogProducts->add($order, $validated['items'], $validated['currency']);
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        } catch (OrderPaymentStateException $exception) {
            throw ValidationException::withMessages(['paid_amount' => $exception->getMessage()]);
        } catch (InvoiceDomainException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse(
            $request,
            ['products', 'order-info', 'history'],
            back()->with('success', 'Produkty zostały dodane do zamówienia.'),
        );
    }
}
