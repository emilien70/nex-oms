<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\CurrencyCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Products\Http\Requests\SaveProductRequest;
use Modules\Products\Http\Requests\UpdateProductActiveRequest;
use Modules\Products\Http\Requests\UpdateProductStockRequest;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductManagementService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    private const PER_PAGE_OPTIONS = ['25', '50', '100', '200', '500', 'all'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'per_page' => ['nullable', Rule::in(self::PER_PAGE_OPTIONS)],
            'tab' => ['nullable', Rule::in(['products', 'bundles'])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'active');
        $perPage = (string) ($filters['per_page'] ?? '50');
        $activeTab = (string) ($filters['tab'] ?? 'products');
        $itemType = $activeTab === 'bundles'
            ? Product::TYPE_BUNDLE
            : Product::TYPE_PRODUCT;

        $query = Product::query()
            ->with([
                'prestashopProductLinks.account',
                'allegroProductLinks.account',
                'bundleComponents.component',
            ])
            ->where('item_type', $itemType)
            ->when($status !== 'all', fn (Builder $query): Builder => $query->where(
                'is_active',
                $status === 'active',
            ))
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
            ->orderBy('id');

        $products = $query
            ->paginate($perPage === 'all' ? max((clone $query)->count(), 1) : (int) $perPage)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'search' => $search,
            'status' => $status,
            'perPage' => $perPage,
            'activeTab' => $activeTab,
            'prestashopAccounts' => PrestashopAccount::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'allegroAccounts' => AllegroAccount::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->filter(fn (AllegroAccount $account): bool => $account->isOperational() && filled($account->allegro_user_id)),
        ]);
    }

    public function create(CurrencyCatalog $currencies): View
    {
        return view('products.form', [
            'product' => new Product([
                'item_type' => Product::TYPE_PRODUCT,
                'currency' => CurrencyCatalog::SYSTEM_CURRENCY,
                'vat_rate' => '23.00',
                'stock_quantity' => 0,
                'is_active' => true,
            ]),
            'currencies' => $currencies->all(),
            'isBundle' => false,
        ]);
    }

    public function store(
        SaveProductRequest $request,
        ProductManagementService $products,
    ): RedirectResponse {
        $product = $products->create($request->validated());

        return redirect()
            ->route('products.edit', $product)
            ->with('success', 'Produkt został zapisany.');
    }

    public function edit(Product $product, CurrencyCatalog $currencies): View
    {
        abort_unless($product->isProduct(), 404);

        return view('products.form', [
            'product' => $product,
            'currencies' => $currencies->all(),
            'isBundle' => false,
        ]);
    }

    public function update(
        SaveProductRequest $request,
        Product $product,
        ProductManagementService $products,
    ): RedirectResponse {
        abort_unless($product->isProduct(), 404);
        $products->update($product, $request->validated());

        return redirect()
            ->route('products.edit', $product)
            ->with('success', 'Produkt został zapisany.');
    }

    public function updateActive(
        UpdateProductActiveRequest $request,
        Product $product,
        ProductManagementService $products,
    ): RedirectResponse {
        $isActive = (bool) $request->validated('is_active');
        $products->setActive($product, $isActive);

        $itemLabel = $product->isBundle() ? 'Zestaw' : 'Produkt';

        return redirect()
            ->back()
            ->with('success', $isActive
                ? $itemLabel.' został aktywowany.'
                : $itemLabel.' został ukryty.');
    }

    public function updateStock(
        UpdateProductStockRequest $request,
        Product $product,
        ProductManagementService $products,
    ): JsonResponse {
        if ($product->isBundle()) {
            throw ValidationException::withMessages([
                'stock_quantity' => 'Stan zestawu wynika ze stanów jego składników.',
            ]);
        }

        $updatedProduct = $products->setStockQuantity(
            $product,
            (int) $request->validated('stock_quantity'),
        );

        return response()->json([
            'stock_quantity' => $updatedProduct->stock_quantity,
            'message' => 'Stan magazynowy został zapisany.',
        ]);
    }

    public function image(Product $product, string $variant): StreamedResponse
    {
        abort_unless(in_array($variant, ['original', 'thumbnail'], true), 404);

        $path = $variant === 'thumbnail' ? $product->thumbnail_path : $product->image_path;
        $mimeType = $variant === 'thumbnail' ? 'image/jpeg' : $product->image_mime_type;
        $prefix = "products/{$product->getKey()}/";

        abort_unless(
            is_string($path)
                && str_starts_with($path, $prefix)
                && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => is_string($mimeType) ? $mimeType : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
