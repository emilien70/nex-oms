@php
    $prestashopLinkGroups = $product->prestashopProductLinks
        ->sortBy(fn ($link) => sprintf(
            '%010d:%020d',
            $link->prestashop_account_id,
            $link->external_product_id,
        ))
        ->groupBy('prestashop_account_id');
@endphp

<div class="product-integrations-list">
    @foreach ($prestashopLinkGroups as $links)
        @php($account = $links->first()->account)
        <button
            class="product-integration-link"
            type="button"
            data-integration-list
            data-account-id="{{ $account->getKey() }}"
            data-account-label="{{ $account->name }} (PrestaShop)"
            title="Pokaż powiązania: {{ $account->name }} (PrestaShop) — {{ $links->count() }}"
        >
            <i class="bi bi-shop" aria-hidden="true"></i>
            <span>{{ $account->name }}</span>
            <span class="product-integration-count" aria-label="Liczba powiązań: {{ $links->count() }}">
                {{ $links->count() }}
            </span>
        </button>

        <div data-integration-group data-account-id="{{ $account->getKey() }}" hidden>
            @foreach ($links as $link)
                <span
                    data-integration-link-record
                    data-link-id="{{ $link->getKey() }}"
                    data-external-product-id="{{ $link->external_product_id }}"
                    data-offer-url="{{ $account->productUrl($link->external_product_id) }}"
                ></span>
            @endforeach
        </div>
    @endforeach

    <button
        class="product-integration-add"
        type="button"
        data-integration-add
        title="Dodaj powiązanie z integracją"
        aria-label="Dodaj powiązanie produktu {{ $product->name }} z integracją"
    >
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
    </button>
</div>
