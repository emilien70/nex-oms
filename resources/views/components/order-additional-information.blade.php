<div class="nex-card order-additional-card">
    <div class="nex-card-header">
        <h2 class="nex-card-title">Informacje dodatkowe</h2>
    </div>
    <div class="nex-card-body">
        <div class="accordion order-information-accordion" id="orderAdditionalInformation">
            @if ($order->source === 'allegro' && $order->allegroOrderLink)
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#orderAllegroDetailsCollapse" aria-expanded="false" aria-controls="orderAllegroDetailsCollapse">Szczegóły zamówienia</button>
                    </h3>
                    <div id="orderAllegroDetailsCollapse" class="accordion-collapse collapse" data-bs-parent="#orderAdditionalInformation" data-allegro-information-panel data-information-loaded="{{ $order->allegroOrderLink->details_snapshot !== null ? '1' : '0' }}" data-refresh-url="{{ route('orders.allegro-information.refresh', $order) }}">
                        <div class="accordion-body">
                            <div class="text-danger small mb-2" role="alert" data-allegro-information-error hidden></div>
                            <div data-allegro-information-body>
                                @include('orders.partials.allegro-details', ['order' => $order, 'link' => $order->allegroOrderLink])
                            </div>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#orderAllegroFeesCollapse" aria-expanded="false" aria-controls="orderAllegroFeesCollapse">Prowizje i opłaty <i class="bi bi-info-circle ms-2" aria-hidden="true" title="Koszty są dodatnie, uznania ujemne. Netto według stawki VAT zwróconej przez Allegro."></i></button>
                    </h3>
                    <div id="orderAllegroFeesCollapse" class="accordion-collapse collapse" data-bs-parent="#orderAdditionalInformation" data-allegro-information-panel data-refresh-on-open="1" data-information-loaded="{{ $order->allegroOrderLink->fees_snapshot !== null ? '1' : '0' }}" data-refresh-url="{{ route('orders.allegro-fees.refresh', $order) }}">
                        <div class="accordion-body">
                            <div class="text-danger small mb-2" role="alert" data-allegro-information-error hidden></div>
                            <div data-allegro-information-body>
                                @include('orders.partials.allegro-fees', ['order' => $order, 'link' => $order->allegroOrderLink])
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#orderPaymentsCollapse" aria-expanded="false" aria-controls="orderPaymentsCollapse">Płatności</button>
                </h3>
                <div id="orderPaymentsCollapse" class="accordion-collapse collapse" data-bs-parent="#orderAdditionalInformation">
                    <div class="accordion-body" data-order-payments-body>
                        @include('orders.partials.payments', ['order' => $order])
                    </div>
                </div>
            </div>
            <div class="accordion-item order-automation-item">
                <h3 class="accordion-header">
                    <button class="accordion-button order-automation-toggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#orderAutomationHistoryCollapse" aria-expanded="false" aria-controls="orderAutomationHistoryCollapse">Historia automatycznych akcji <i class="bi bi-chevron-down ms-2" aria-hidden="true"></i></button>
                </h3>
                <div id="orderAutomationHistoryCollapse" class="accordion-collapse collapse" data-bs-parent="#orderAdditionalInformation">
                    <div class="accordion-body">
                        <div class="text-danger small mb-2" role="alert" data-automation-history-error hidden></div>
                        <div data-order-automation-history-body>
                            @include('orders.partials.automation-history', ['automationHistory' => $automationHistory])
                        </div>
                    </div>
                </div>
            </div>
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#orderHistoryCollapse" aria-expanded="false" aria-controls="orderHistoryCollapse">Historia zmian w zamówieniu</button>
                </h3>
                <div id="orderHistoryCollapse" class="accordion-collapse collapse" data-bs-parent="#orderAdditionalInformation">
                    <div class="accordion-body" data-order-history-body>
                        @include('orders.partials.history', ['order' => $order])
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
