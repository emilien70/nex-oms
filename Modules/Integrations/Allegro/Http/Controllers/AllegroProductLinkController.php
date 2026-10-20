<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Integrations\Allegro\Http\Requests\SaveAllegroProductLinkRequest;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Integrations\Allegro\Services\AllegroProductLinkService;
use Modules\Products\Models\Product;

class AllegroProductLinkController extends Controller
{
    public function store(SaveAllegroProductLinkRequest $request, Product $product, AllegroAccount $allegroAccount, AllegroProductLinkService $links): JsonResponse
    {
        $link = $links->create($product, $allegroAccount, $request->validated('external_offer_id'));

        return $this->response($product, 'Powiązanie zostało dodane.', $link);
    }

    public function update(SaveAllegroProductLinkRequest $request, Product $product, AllegroAccount $allegroAccount, AllegroProductLink $allegroProductLink, AllegroProductLinkService $links): JsonResponse
    {
        $link = $links->update($product, $allegroAccount, $allegroProductLink, $request->validated('external_offer_id'));

        return $this->response($product, 'Powiązanie zostało zapisane.', $link);
    }

    public function destroy(Product $product, AllegroAccount $allegroAccount, AllegroProductLink $allegroProductLink, AllegroProductLinkService $links): JsonResponse
    {
        $links->delete($product, $allegroAccount, $allegroProductLink);

        return $this->response($product, 'Powiązanie zostało usunięte.');
    }

    private function response(Product $product, string $message, ?AllegroProductLink $link = null): JsonResponse
    {
        $product->load(['prestashopProductLinks.account', 'allegroProductLinks.account']);

        return response()->json([
            'message' => $message,
            'link' => $link === null ? null : ['id' => $link->id, 'account_id' => $link->allegro_account_id, 'external_offer_id' => $link->external_offer_id, 'offer_url' => $link->offerUrl()],
            'html' => view('products.partials.integration-links', compact('product'))->render(),
        ]);
    }
}
