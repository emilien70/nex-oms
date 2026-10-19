@forelse ($products as $product)
    @php($catalogStock = $product->availableStockQuantity())
    <div class="catalog-product-result" data-catalog-result data-product-id="{{ $product->getKey() }}">
        <div class="catalog-product-result-main">
            @if ($product->thumbnail_path)
                <img
                    class="catalog-product-result-image"
                    src="{{ route('products.image', [$product, 'thumbnail']) }}"
                    alt=""
                    loading="lazy"
                >
            @else
                <span class="catalog-product-result-image catalog-product-result-placeholder" aria-hidden="true">
                    <i class="bi bi-image"></i>
                </span>
            @endif
            <div class="catalog-product-result-copy">
                <strong>{{ $product->name }}</strong>
                <span>ID {{ $product->getKey() }}</span>
            </div>
        </div>
        <span class="catalog-product-result-stock {{ $catalogStock < 0 ? 'is-negative' : '' }}">
            {{ $catalogStock }} szt.
        </span>
        <span class="catalog-product-result-price">
            {{ number_format((float) $product->price_gross, 2, ',', ' ') }} {{ $product->currency }}
        </span>
        <button
            class="btn btn-sm btn-outline-primary catalog-product-add"
            type="button"
            data-catalog-add
            data-product-id="{{ $product->getKey() }}"
            data-product-name="{{ $product->name }}"
            data-product-price="{{ $product->price_gross }}"
            data-product-currency="{{ $product->currency }}"
            data-product-stock="{{ $catalogStock }}"
            data-product-image="{{ $product->thumbnail_path ? route('products.image', [$product, 'thumbnail']) : '' }}"
        >
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            Dodaj
        </button>
    </div>
@empty
    <div class="catalog-product-results-empty">
        Nie znaleziono aktywnych produktów ani zestawów spełniających kryteria.
    </div>
@endforelse

@if ($products->hasPages())
    <div class="catalog-product-pagination">
        <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-catalog-page="{{ max(1, $products->currentPage() - 1) }}"
            @disabled($products->onFirstPage())
            aria-label="Poprzednia strona"
        >
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        <span>
            {{ $products->firstItem() }}-{{ $products->lastItem() }} z {{ $products->total() }}
        </span>
        <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-catalog-page="{{ min($products->lastPage(), $products->currentPage() + 1) }}"
            @disabled(! $products->hasMorePages())
            aria-label="Następna strona"
        >
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
    </div>
@elseif ($products->total() > 0)
    <div class="catalog-product-pagination is-single-page">
        <span>{{ $products->total() }} {{ $products->total() === 1 ? 'pozycja' : 'pozycji' }}</span>
    </div>
@endif
