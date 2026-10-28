@if($smsReady)
<form class="d-none mb-3" method="POST" action="{{ route('orders.sms.store', $order) }}" data-sms-form>
    @csrf<input type="hidden" name="idempotency_key" value="{{ Illuminate\Support\Str::uuid() }}">
    @if(!$smsEnabled)<div class="alert alert-warning py-2 small">Integracja MacroDroid jest wylaczona. <a href="{{ route('integrations.macrodroid.edit') }}">Konfiguracja bramki SMS</a></div>@endif
    <div class="row g-2 mb-2"><div class="col-md-7"><label class="form-label" for="orderSmsTemplate">Szablon SMS</label><select class="form-select form-select-sm" name="sms_template_id" id="orderSmsTemplate"><option value="">Bez szablonu</option>@foreach($smsTemplates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></div><div class="col-md-5"><label class="form-label" for="orderSmsRecipient">Numer odbiorcy</label><input class="form-control form-control-sm" type="tel" name="recipient" id="orderSmsRecipient" maxlength="32" value="{{ $order->customer_phone }}" required></div></div>
    <label class="form-label" for="orderSmsBody">Tresc SMS</label><textarea class="form-control form-control-sm" rows="5" maxlength="12000" name="body" id="orderSmsBody" required></textarea>
    <div class="d-flex flex-wrap justify-content-between gap-2 mt-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-sms-preview><i class="bi bi-eye me-1"></i>Podglad i liczba czesci</button><button class="btn btn-primary btn-sm" type="submit" @disabled(!$smsEnabled)><i class="bi bi-send me-1"></i>Wyslij SMS</button></div>
    <div class="small mt-2" role="status" data-sms-result></div><pre class="small text-wrap mt-2" data-sms-preview-body></pre>
</form>
<div data-sms-history>@include('components.order-sms-history', ['messages'=>$smsMessages])</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-order-email-exchange]'), form = root.querySelector('[data-sms-form]');
    const templates = {{ Illuminate\Support\Js::from($smsTemplates->pluck('body_template','id')) }};
    const output = form.querySelector('[data-sms-result]'), history = root.querySelector('[data-sms-history]');
    const csrf = form.elements._token.value;
    let pollTimer, polls = 0;
    const request = async (url, options = {}) => {
        const response = await fetch(url, {...options, cache:'no-store', headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,...options.headers}});
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Operacja nie powiodla sie.');
        return data;
    };
    const refresh = async () => {
        clearTimeout(pollTimer);
        try {
            const data = await request(@json(route('orders.sms.history',$order)));
            history.innerHTML = data.html;
            if (data.pending && ++polls < 120) pollTimer = setTimeout(refresh, 5000);
        } catch { if (++polls < 120) pollTimer = setTimeout(refresh, 10000); }
    };
    root.querySelectorAll('[name="order_contact_channel"]').forEach(radio => radio.addEventListener('change', () => {
        const sms = radio.value === 'sms';
        form.classList.toggle('d-none', !sms); root.querySelector('[data-order-email-form]').classList.toggle('d-none', sms);
    }));
    form.elements.sms_template_id.addEventListener('change', () => {
        if (form.elements.sms_template_id.value && form.elements.body.value && !confirm('Zastapic obecna tresc szablonem SMS?')) { form.elements.sms_template_id.value=''; return; }
        form.elements.body.value = templates[form.elements.sms_template_id.value] || '';
        output.textContent = ''; form.querySelector('[data-sms-preview-body]').textContent = '';
    });
    let previewTimer, revision=0;
    const preview = async () => {
        const currentRevision = ++revision;
        try {
            const data = await request(@json(route('orders.sms.preview')), {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({body:form.elements.body.value,order_id:{{ $order->id }}})});
            if (currentRevision !== revision) return;
            output.textContent = `${data.encoding} / ${data.units} jednostek / ${data.parts} czesci`;
            form.querySelector('[data-sms-preview-body]').textContent = data.body;
        } catch (error) { output.textContent = error.message; }
    };
    form.querySelector('[data-sms-preview]').addEventListener('click', preview);
    form.elements.body.addEventListener('input', () => { clearTimeout(previewTimer); previewTimer=setTimeout(preview, 900); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); const button = form.querySelector('[type="submit"]'); if (button.disabled) return;
        if (!confirm(`Dodac SMS do wysylki na numer ${form.elements.recipient.value}?`)) return;
        button.disabled = true;
        try {
            await request(form.action, {method:'POST',body:new FormData(form)});
            output.textContent = 'SMS dodano do kolejki. Wynik pojawi sie w historii.';
            const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
            const h=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
            form.elements.idempotency_key.value = `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
            form.elements.body.value = ''; form.elements.sms_template_id.value = ''; form.querySelector('[data-sms-preview-body]').textContent='';
            polls=0; await refresh();
        } catch (error) { output.textContent=error.message; }
        finally { button.disabled=false; }
    });
    history.addEventListener('click', async event => {
        const button = event.target.closest('[data-sms-cancel]'); if (!button || !confirm('Anulowac nierozpoczete zlecenie SMS?')) return;
        button.disabled=true;
        try { await request(button.dataset.smsCancel,{method:'POST'}); await refresh(); }
        catch (error) { output.textContent=error.message; button.disabled=false; }
    });
    @if($smsMessages->contains(fn($message) => !$message->terminal())) refresh(); @endif
});
</script>
@endif
