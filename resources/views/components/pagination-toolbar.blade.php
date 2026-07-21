@props([
    'paginator',
    'perPageOptions' => [],
    'perPage' => null,
    'ariaLabel' => 'Paginacja',
])

@php
    $currentPerPage = (int) ($perPage ?? $paginator->perPage());
@endphp

<div {{ $attributes->class(['nex-pagination-toolbar']) }} aria-label="{{ $ariaLabel }}">
    <div class="dropdown">
        <button class="nex-page-range dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Zmie&#324; liczb&#281; pozycji na stronie">
            {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }}
        </button>
        <ul class="dropdown-menu dropdown-menu-end nex-page-size-menu">
            @foreach ($perPageOptions as $pageSize)
                <li>
                    <a class="dropdown-item {{ $currentPerPage === (int) $pageSize ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => 1, 'per_page' => $pageSize]) }}">
                        {{ $pageSize }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
    <span class="nex-pagination-total">z {{ $paginator->total() }} pozycji</span>
    <div class="btn-group btn-group-sm nex-page-navigation" role="group" aria-label="Strony">
        @if ($paginator->onFirstPage())
            <span class="btn btn-outline-secondary disabled" aria-hidden="true">&#8249;</span>
        @else
            <a class="btn btn-outline-secondary" href="{{ $paginator->previousPageUrl() }}" aria-label="Poprzednia strona">&#8249;</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="btn btn-outline-secondary" href="{{ $paginator->nextPageUrl() }}" aria-label="Nast&#281;pna strona">&#8250;</a>
        @else
            <span class="btn btn-outline-secondary disabled" aria-hidden="true">&#8250;</span>
        @endif
    </div>
</div>
