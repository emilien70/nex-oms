<div class="nex-card order-additional-card">
    <div class="nex-card-header">
        <h2 class="nex-card-title">Informacje dodatkowe</h2>
    </div>
    <div class="nex-card-body">
        <div class="accordion order-information-accordion" id="orderAdditionalInformation">
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
