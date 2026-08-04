@php($buyer = $invoice->buyer_snapshot ?? [])
@php($currentBuyer = ['name'=>$order->billing_name,'company_name'=>$order->billing_company_name,'tax_id'=>$order->billing_tax_id,'street'=>$order->billing_street,'building_number'=>$order->billing_building_number,'apartment_number'=>$order->billing_apartment_number,'postal_code'=>$order->billing_postal_code,'city'=>$order->billing_city,'province'=>$order->billing_province,'country_code'=>$order->billing_country_code,'email'=>$order->billing_email,'phone'=>$order->billing_phone])
<section class="invoice-edit-card">
    <header class="invoice-edit-card-header"><h2 class="fs-6 mb-0">Dane nabywcy</h2></header>
    <div class="row g-0">
        <div class="col-lg-7 invoice-edit-card-body">
            <form id="invoice-buyer-form" method="POST" action="{{ route('invoices.buyer.update', $invoice) }}" data-invoice-ajax-form>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                @include('invoices.edit.partials.address-fields', ['snapshot'=>$buyer,'prefix'=>''])
                <button class="btn btn-sm btn-primary mt-3" type="submit">Zapisz</button>
            </form>
        </div>
        <aside class="col-lg-5 invoice-current-data">
            <div class="d-flex justify-content-between align-items-center mb-3"><strong>Aktualne dane w zamówieniu {{ $order->id }}</strong><button class="btn btn-sm btn-outline-secondary" type="button" data-copy-address data-form="#invoice-buyer-form" data-values="{{ json_encode($currentBuyer) }}">Skopiuj aktualne dane do Faktury</button></div>
            <dl><dt>Imię i nazwisko</dt><dd>{{ $order->billing_name ?: '...' }}</dd><dt>Firma</dt><dd>{{ $order->billing_company_name ?: '...' }}</dd><dt>NIP</dt><dd>{{ $order->billing_tax_id ?: '...' }}</dd><dt>Adres</dt><dd>{{ trim(($order->billing_street ?? '').' '.($order->billing_building_number ?? '').($order->billing_apartment_number ? '/'.$order->billing_apartment_number : '')) ?: '...' }}</dd><dt>Kod i miasto</dt><dd>{{ trim(($order->billing_postal_code ?? '').' '.($order->billing_city ?? '')) ?: '...' }}</dd><dt>Kraj</dt><dd>{{ $countries[$order->billing_country_code] ?? '...' }}</dd></dl>
        </aside>
    </div>
</section>
