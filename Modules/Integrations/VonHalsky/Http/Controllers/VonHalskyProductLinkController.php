<?php

namespace Modules\Integrations\VonHalsky\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Integrations\VonHalsky\Http\Requests\SaveVonHalskyProductLinkRequest;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyProductLink;
use Modules\Integrations\VonHalsky\Services\VonHalskyProductLinkService;
use Modules\Products\Models\Product;

class VonHalskyProductLinkController extends Controller
{
    public function store(SaveVonHalskyProductLinkRequest $request, Product $product, VonHalskyAccount $vonHalskyAccount,
        VonHalskyProductLinkService $links): JsonResponse
    {
        $link = $links->save($product, $vonHalskyAccount, $request->validated('external_offer_id'), (int) $request->validated('expected_connection_revision'));

        return $this->response($product, 'Powiazanie zostalo dodane.', $link);
    }

    public function update(SaveVonHalskyProductLinkRequest $request, Product $product, VonHalskyAccount $vonHalskyAccount,
        VonHalskyProductLink $vonHalskyProductLink, VonHalskyProductLinkService $links): JsonResponse
    {
        $link = $links->save($product, $vonHalskyAccount, $request->validated('external_offer_id'),
            (int) $request->validated('expected_connection_revision'), $vonHalskyProductLink);

        return $this->response($product, 'Powiazanie zostalo zapisane.', $link);
    }

    public function destroy(SaveVonHalskyProductLinkRequest $request, Product $product, VonHalskyAccount $vonHalskyAccount,
        VonHalskyProductLink $vonHalskyProductLink, VonHalskyProductLinkService $links): JsonResponse
    {
        $links->delete($product, $vonHalskyAccount, $vonHalskyProductLink, (int) $request->validated('expected_connection_revision'));

        return $this->response($product, 'Powiazanie zostalo usuniete.');
    }

    private function response(Product $product, string $message, ?VonHalskyProductLink $link = null): JsonResponse
    {
        $product->load(['prestashopProductLinks.account', 'allegroProductLinks.account', 'vonHalskyProductLinks.account']);

        return response()->json(['message' => $message,
            'link' => $link === null ? null : ['id' => $link->id, 'account_id' => $link->von_halsky_account_id,
                'external_offer_id' => $link->external_offer_id, 'offer_url' => null],
            'html' => view('products.partials.integration-links', compact('product'))->render()]);
    }
}
