<nav class="d-flex flex-wrap gap-3 mb-3" aria-label="Ustawienia faktur">
    <a href="{{ route('invoices.series.index') }}" @if (request()->routeIs('invoices.series.*')) aria-current="page" @endif>Serie numeracji</a>
    <a href="{{ route('invoices.jpk-profile.edit') }}" @if (request()->routeIs('invoices.jpk-profile.*')) aria-current="page" @endif>Dane podatnika JPK</a>
</nav>
