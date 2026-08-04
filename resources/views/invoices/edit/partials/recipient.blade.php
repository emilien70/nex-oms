@php($recipient = $invoice->recipient_snapshot ?? [])
@php($currentRecipient = ['name'=>$order->shipping_name,'company_name'=>$order->shipping_company_name,'street'=>$order->shipping_street,'building_number'=>$order->shipping_building_number,'apartment_number'=>$order->shipping_apartment_number,'postal_code'=>$order->shipping_postal_code,'city'=>$order->shipping_city,'province'=>$order->shipping_province,'country_code'=>$order->shipping_country_code,'email'=>$order->shipping_email,'phone'=>$order->shipping_phone])
<section class="invoice-edit-card">
    <header class="invoice-edit-card-header"><h2 class="fs-6 mb-0">Dane odbiorcy</h2></header>
    <div class="row g-0">
        <div class="col-lg-7 invoice-edit-card-body">
            <form id="invoice-recipient-form" method="POST" action="{{ route('invoices.recipient.update', $invoice) }}" data-invoice-ajax-form>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                @include('invoices.edit.partials.address-fields', ['snapshot'=>$recipient,'prefix'=>'','hideTaxId'=>true])
                <button class="btn btn-sm btn-primary mt-3" type="submit">Zapisz</button>
            </form>
        </div>
        <aside class="col-lg-5 invoice-current-data">
            <div class="d-flex justify-content-between align-items-center mb-3"><strong>Aktualne dane dostawy</strong><button class="btn btn-sm btn-outline-secondary" type="button" data-copy-address data-form="#invoice-recipient-form" data-values="{{ json_encode($currentRecipient) }}">Skopiuj aktualne dane do Faktury</button></div>
            <dl><dt>Imię i nazwisko</dt><dd>{{ $order->shipping_name ?: '...' }}</dd><dt>Firma</dt><dd>{{ $order->shipping_company_name ?: '...' }}</dd><dt>Adres</dt><dd>{{ trim(($order->shipping_street ?? '').' '.($order->shipping_building_number ?? '').($order->shipping_apartment_number ? '/'.$order->shipping_apartment_number : '')) ?: '...' }}</dd><dt>Kod i miasto</dt><dd>{{ trim(($order->shipping_postal_code ?? '').' '.($order->shipping_city ?? '')) ?: '...' }}</dd><dt>Kraj</dt><dd>{{ $countries[$order->shipping_country_code] ?? '...' }}</dd></dl>
        </aside>
    </div>
</section>
