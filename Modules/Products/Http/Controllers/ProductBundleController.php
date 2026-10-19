<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\CurrencyCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Modules\Products\Http\Requests\SaveProductBundleRequest;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductBundleManagementService;

class ProductBundleController extends Controller
{
    public function create(CurrencyCatalog $currencies): View
    {
        return $this->formView(
            new Product([
                'item_type' => Product::TYPE_BUNDLE,
                'currency' => CurrencyCatalog::SYSTEM_CURRENCY,
                'vat_rate' => '23.00',
                'stock_quantity' => 0,
                'is_active' => true,
            ]),
            $currencies,
        );
    }

    public function store(
        SaveProductBundleRequest $request,
        ProductBundleManagementService $bundles,
    ): RedirectResponse {
        $bundle = $bundles->create($request->validated());

        return redirect()
            ->route('product-bundles.edit', $bundle)
            ->with('success', 'Zestaw został zapisany.');
    }

    public function edit(Product $bundle, CurrencyCatalog $currencies): View
    {
        abort_unless($bundle->isBundle(), 404);

        return $this->formView(
            $bundle->load('bundleComponents.component'),
            $currencies,
        );
    }

    public function update(
        SaveProductBundleRequest $request,
        Product $bundle,
        ProductBundleManagementService $bundles,
    ): RedirectResponse {
        abort_unless($bundle->isBundle(), 404);
        $bundles->update($bundle, $request->validated());

        return redirect()
            ->route('product-bundles.edit', $bundle)
            ->with('success', 'Zestaw został zapisany.');
    }

    private function formView(Product $bundle, CurrencyCatalog $currencies): View
    {
        return view('products.form', [
            'product' => $bundle,
            'currencies' => $currencies->all(),
            'isBundle' => true,
            'componentProducts' => Product::query()
                ->where('item_type', Product::TYPE_PRODUCT)
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ]);
    }
}
