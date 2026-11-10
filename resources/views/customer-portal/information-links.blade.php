@if ($information ?? null)
    <nav class="customer-information-links" aria-label="{{ $informationLabel ?? 'Informacje dla klientów' }}">
        @if ($includeInformationPage ?? true)
            <a href="{{ $information['url'] }}">Informacje dla klientów</a>
        @endif
        @foreach ($information['links'] as $label => $url)
            <a href="{{ $url }}" rel="noreferrer">{{ $label }}</a>
        @endforeach
    </nav>
@endif
