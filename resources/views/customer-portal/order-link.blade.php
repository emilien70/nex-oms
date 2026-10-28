@php($portalOrderLink = app(\Modules\CustomerPortal\Services\PortalAccessService::class)->orderLink($order))
<div class="management-portal-link" data-customer-portal-link>
    <div class="management-portal-label">Strona z informacjami o zamówieniu:
        <button class="management-portal-help" type="button" data-customer-portal-help aria-label="Informacje o stronie zamówienia" title="Strona informacyjna jest przygotowywana automatycznie dla zamówienia. Możesz przekazać jej adres klientowi lub użyć zmiennej [link_zamowienia] w szablonie e-mail. Zakres widocznych informacji i dostępność strony ustawisz w Ustawienia → Strony informacyjne."><i class="bi bi-question-circle" aria-hidden="true"></i></button>
    </div>
    @if($portalOrderLink['active'])
        <a class="management-portal-url" href="{{ $portalOrderLink['url'] }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">{{ $portalOrderLink['url'] }}</a>
    @elseif($portalOrderLink['url'])
        <span class="management-portal-unavailable text-break">{{ $portalOrderLink['url'] }}</span>
        <span class="management-portal-unavailable">{{ $portalOrderLink['reason'] }}</span>
    @else
        <span class="management-portal-unavailable">{{ $portalOrderLink['reason'] }}</span>
    @endif
</div>
