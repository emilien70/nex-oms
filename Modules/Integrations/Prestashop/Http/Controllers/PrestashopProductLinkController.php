<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Http\Requests\SavePrestashopProductLinkRequest;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopProductLink;
use Modules\Integrations\Prestashop\Services\PrestashopProductLinkService;
use Modules\Products\Models\Product;

class PrestashopProductLinkController extends Controller
{
    public function store(
        SavePrestashopProductLinkRequest $request,
        Product $product,
        PrestashopAccount $prestashopAccount,
        PrestashopProductLinkService $links,
    ): JsonResponse {
        if (! $prestashopAccount->is_active) {
            throw ValidationException::withMessages([
                'integration' => 'Wybrana integracja PrestaShop nie jest aktywna.',
            ]);
        }

        $link = $links->create(
            $product,
            $prestashopAccount,
            (int) $request->validated('external_product_id'),
        );

        return $this->response($product, 'Powiązanie zostało dodane.', $link);
    }

    public function update(
        SavePrestashopProductLinkRequest $request,
        Product $product,
        PrestashopAccount $prestashopAccount,
        PrestashopProductLink $prestashopProductLink,
        PrestashopProductLinkService $links,
    ): JsonResponse {
        if (! $prestashopAccount->is_active) {
            throw ValidationException::withMessages([
                'integration' => 'Wybrana integracja PrestaShop nie jest aktywna.',
            ]);
        }

        $link = $links->update(
            $product,
            $prestashopAccount,
            $prestashopProductLink,
            (int) $request->validated('external_product_id'),
        );

        return $this->response($product, 'Powiązanie zostało zapisane.', $link);
    }

    public function destroy(
        Product $product,
        PrestashopAccount $prestashopAccount,
        PrestashopProductLink $prestashopProductLink,
        PrestashopProductLinkService $links,
    ): JsonResponse {
        $links->delete($product, $prestashopAccount, $prestashopProductLink);

        return $this->response($product, 'Powiązanie zostało usunięte.');
    }

    private function response(
        Product $product,
        string $message,
        ?PrestashopProductLink $link = null,
    ): JsonResponse {
        $product->load(['prestashopProductLinks.account', 'allegroProductLinks.account']);

        return response()->json([
            'message' => $message,
            'link' => $link === null ? null : [
                'id' => $link->getKey(),
                'account_id' => $link->prestashop_account_id,
                'external_product_id' => $link->external_product_id,
                'offer_url' => $link->account()->firstOrFail()->productUrl($link->external_product_id),
            ],
            'html' => view('products.partials.integration-links', [
                'product' => $product,
            ])->render(),
        ]);
    }
}
