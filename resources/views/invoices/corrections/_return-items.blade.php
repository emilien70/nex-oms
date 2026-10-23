<section class="correction-items-card p-3" data-return-correction-items>
    <h2 class="h6 mb-3">Pozycje zwrotu</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Produkt</th><th class="text-end">Cena brutto</th><th class="text-end">Ilość przed</th><th style="width:150px">Do korekty</th><th class="text-end">Pozostanie</th></tr></thead>
            <tbody>
            @foreach ($returnCorrection['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td><td class="text-end">{{ $row['unit_price_gross'] }} {{ $sourceInvoice->currency }}</td>
                    <td class="text-end">{{ $row['before'] }}</td>
                    <td><input class="form-control form-control-sm" type="number" min="0" max="{{ $row['maximum'] }}" step="1" required name="return_quantities[{{ $row['source_item_id'] }}]" value="{{ old('return_quantities.'.$row['source_item_id'], $row['selected']) }}" aria-label="Do korekty: {{ $row['name'] }}" data-return-quantity data-before="{{ $row['before'] }}"></td>
                    <td class="text-end" data-return-remaining>{{ $row['before'] - $row['selected'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @php
        $shippingAmount = $returnCorrection['snapshot']['shipping_amount'] ?? '0.00';
        $shippingSelected = (bool) old('return_shipping', $shippingAmount !== '0.00');
    @endphp
    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
        <input type="hidden" name="return_shipping" value="0">
        <input type="hidden" name="return_shipping" value="0">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="returnShipping" name="return_shipping" value="1" @checked($shippingSelected) @disabled(! $returnCorrection['shipping']) data-return-shipping><label class="form-check-label" for="returnShipping">Uwzględnij korektę kosztu dostawy</label></div>
        @if ($returnCorrection['shipping'])
            <div class="d-flex align-items-center gap-2"><label for="returnShippingAmount">Kwota brutto</label><input class="form-control form-control-sm" style="width:130px" type="number" min="0.01" step="0.01" max="{{ $returnCorrection['shipping']['maximum'] }}" name="return_shipping_amount" id="returnShippingAmount" value="{{ old('return_shipping_amount', $shippingAmount !== '0.00' ? $shippingAmount : $returnCorrection['shipping']['maximum']) }}" @disabled(! $shippingSelected) data-return-shipping-amount><span>{{ $sourceInvoice->currency }}</span></div>
        @endif
    </div>
</section>
