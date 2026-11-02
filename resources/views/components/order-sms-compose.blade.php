@if($smsReady)
<form class="order-email-compose order-sms-compose d-none" method="POST" action="{{ route('orders.sms.store', $order) }}" data-sms-form>
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ Illuminate\Support\Str::uuid() }}">
    @if(!$smsEnabled)<div class="alert alert-warning py-2 small mb-0">Integracja MacroDroid jest wyłączona. <a href="{{ route('integrations.macrodroid.edit') }}">Konfiguracja bramki SMS</a></div>@endif
    <div class="order-email-form-row" data-sms-template-row>
        <label class="form-label mb-0" for="orderSmsTemplate">Szablon:</label>
        <select class="form-select form-select-sm" name="sms_template_id" id="orderSmsTemplate">
            <option value="">Nie wybrano</option>
            @foreach($smsTemplates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach
        </select>
    </div>
    <div class="order-email-editor-shell">
        <label class="visually-hidden" for="orderSmsBody">Treść wiadomości SMS</label>
        <textarea class="order-email-editor order-sms-editor" rows="10" maxlength="12000" name="body" id="orderSmsBody" placeholder="Treść wiadomości" required></textarea>
    </div>
    <div class="order-sms-counter-row">
        <span class="order-sms-counter" data-sms-counter aria-live="polite">Ilość znaków: <span data-sms-character-count>0</span></span>
        <button class="btn btn-outline-secondary btn-sm order-sms-preview-button" type="button" data-sms-preview title="Podgląd wiadomości i liczba części SMS" aria-label="Podgląd wiadomości i liczba części SMS"><i class="bi bi-eye" aria-hidden="true"></i></button>
    </div>
    <pre class="order-email-message-body order-sms-preview d-none" data-sms-preview-body></pre>
    <div class="order-email-form-row order-email-send-row">
        <label class="form-label mb-0" for="orderSmsSender">Nadawca:</label>
        <div class="input-group input-group-sm">
            <input class="form-control" id="orderSmsSender" type="text" value="{{ $smsSenderPhone }}" placeholder="Nie uzupełniono numeru karty SIM" readonly aria-readonly="true">
            <button class="btn btn-primary" type="submit" @disabled(!$smsEnabled)>Wyślij</button>
        </div>
    </div>
    <div class="small d-none" role="status" data-sms-result></div>
</form>
<div data-sms-history>@include('components.order-sms-history', ['messages'=>$smsMessages])</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-order-email-exchange]');
    const form = root.querySelector('[data-sms-form]');
    const channels = root.querySelector('[data-contact-channels]');
    const emailForm = root.querySelector('[data-order-email-form]');
    const templates = {{ Illuminate\Support\Js::from($smsTemplates->pluck('body_template','id')) }};
    const output = form.querySelector('[data-sms-result]');
    const history = root.querySelector('[data-sms-history]');
    const counter = form.querySelector('[data-sms-counter]');
    const characterCount = form.querySelector('[data-sms-character-count]');
    const previewBody = form.querySelector('[data-sms-preview-body]');
    const csrf = form.elements._token.value;
    let pollTimer, previewTimer, polls = 0, revision = 0, sending = false;
    const setResult = (text = '') => {
        output.textContent = text;
        output.classList.toggle('d-none', !text);
    };
    const announceHistory = hasMessages => root.dispatchEvent(new CustomEvent('nexoms:sms-history-updated', {detail: {hasMessages}}));
    const request = async (url, options = {}) => {
        const response = await fetch(url, {...options, cache:'no-store', headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,...options.headers}});
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Operacja nie powiodła się.');
        return data;
    };
    const refresh = async () => {
        clearTimeout(pollTimer);
        try {
            const data = await request(@json(route('orders.sms.history',$order)));
            history.innerHTML = data.html;
            announceHistory(Boolean(history.querySelector('[data-sms-id]')));
            if (data.pending && ++polls < 120) pollTimer = setTimeout(refresh, 5000);
        } catch { if (++polls < 120) pollTimer = setTimeout(refresh, 10000); }
    };
    root.querySelectorAll('[name="order_contact_channel"]').forEach(radio => radio.addEventListener('change', () => {
        const sms = radio.value === 'sms';
        form.classList.toggle('d-none', !sms);
        emailForm.classList.toggle('d-none', sms);
        if (sms) form.querySelector('[data-sms-template-row]').after(channels);
        else emailForm.before(channels);
    }));
    const resetPreview = () => {
        clearTimeout(previewTimer);
        revision++;
        characterCount.textContent = Array.from(form.elements.body.value).length;
        counter.removeAttribute('title');
        previewBody.textContent = '';
        previewBody.classList.add('d-none');
    };
    const preview = async (showBody = false) => {
        if (!form.elements.body.value.trim()) return;
        const currentRevision = ++revision;
        try {
            const data = await request(@json(route('orders.sms.preview')), {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({body:form.elements.body.value,order_id:{{ $order->id }}})});
            if (currentRevision !== revision) return;
            characterCount.textContent = Array.from(data.body).length;
            counter.title = `${data.encoding} / jednostki: ${data.units} / części SMS: ${data.parts}`;
            if (showBody) {
                previewBody.textContent = data.body;
                previewBody.classList.remove('d-none');
            }
            setResult();
        } catch (error) { if (currentRevision === revision) setResult(error.message); }
    };
    form.elements.sms_template_id.addEventListener('change', () => {
        if (form.elements.sms_template_id.value && form.elements.body.value && !confirm('Zastąpić obecną treść szablonem SMS?')) { form.elements.sms_template_id.value=''; return; }
        form.elements.body.value = templates[form.elements.sms_template_id.value] || '';
        resetPreview();
        setResult();
        previewTimer = setTimeout(() => preview(), 900);
    });
    form.querySelector('[data-sms-preview]').addEventListener('click', () => preview(true));
    form.elements.body.addEventListener('input', () => {
        resetPreview();
        setResult();
        previewTimer = setTimeout(() => preview(), 900);
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        if (sending || button.disabled) return;
        sending = true;
        button.disabled = true;
        setResult();
        clearTimeout(previewTimer);
        revision++;
        try {
            if (typeof root.resolveMessageRecipient !== 'function' || typeof root.assertMessageContactSaved !== 'function') {
                throw new Error('Nie udało się przygotować wysyłki. Odśwież stronę i spróbuj ponownie.');
            }
            const formData = new FormData(form);
            const recipient = await root.resolveMessageRecipient('sms');
            root.assertMessageContactSaved();
            if (!confirm(`Wysłać SMS na numer ${recipient.recipient}?`)) return;
            root.assertMessageContactSaved();
            formData.set('recipient', recipient.recipient);
            formData.set('recipient_token', recipient.recipient_token);
            await request(form.action, {method:'POST',body:formData});
            setResult('SMS dodano do kolejki. Wynik pojawi się w historii.');
            announceHistory(true);
            const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
            const h=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
            form.elements.idempotency_key.value = `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
            form.elements.body.value = '';
            form.elements.sms_template_id.value = '';
            resetPreview();
            polls=0;
            await refresh();
        } catch (error) { setResult(error.message); }
        finally { sending=false; button.disabled=false; }
    });
    history.addEventListener('click', async event => {
        const button = event.target.closest('[data-sms-cancel]');
        if (!button || !confirm('Anulować nierozpoczęte zlecenie SMS?')) return;
        button.disabled=true;
        try { await request(button.dataset.smsCancel,{method:'POST'}); await refresh(); }
        catch (error) { setResult(error.message); button.disabled=false; }
    });
    @if($smsMessages->contains(fn($message) => !$message->terminal())) refresh(); @endif
});
</script>
@endif
