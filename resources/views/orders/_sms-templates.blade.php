<section class="mt-4" aria-labelledby="smsTemplatesTitle">
    @if($errors->smsTemplate->any())<div class="alert alert-danger">{{ $errors->smsTemplate->first() }}</div>@endif
    <div class="d-flex justify-content-between align-items-center gap-2 bg-primary text-white p-3 rounded-top">
        <h2 class="fs-6 mb-0" id="smsTemplatesTitle">Szablony SMS</h2>
        <button type="button" class="btn btn-sm btn-light" data-sms-template-create @disabled(!$smsTemplates)><i class="bi bi-plus-lg me-1"></i>Dodaj szablon SMS</button>
    </div>
    @if(!$smsTemplates)<div class="p-3 text-secondary">Uruchom migracje SMS, aby dodac szablony.</div>@else
    <div class="table-responsive bg-white"><table class="table table-sm align-middle mb-0"><thead><tr><th class="p-3">Nazwa wyswietlana</th><th class="p-3">Tresc wiadomosci (poczatek)</th><th class="p-3 text-end">Edytuj</th><th class="p-3 text-end">Usun</th></tr></thead><tbody>
        @forelse($smsTemplates as $template)<tr>
            <td class="p-3">{{ $template->name }}</td><td class="p-3">{{ Illuminate\Support\Str::limit($template->body_template, 100) }}</td>
            <td class="p-3 text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-sms-template-edit="{{ $template->id }}" title="Edytuj szablon SMS" aria-label="Edytuj szablon SMS"><i class="bi bi-pencil"></i></button></td>
            <td class="p-3 text-end"><form method="POST" action="{{ route('orders.sms-templates.destroy', $template) }}" onsubmit="return confirm('Usunac szablon SMS? Istniejaca historia wiadomosci pozostanie zachowana.')">@csrf @method('DELETE')<input type="hidden" name="expected_lock_version" value="{{ $template->lock_version }}"><button class="btn btn-sm btn-outline-secondary" title="Usun szablon SMS" aria-label="Usun szablon SMS"><i class="bi bi-trash"></i></button></form></td>
        </tr>@empty<tr><td colspan="4" class="p-3 text-secondary">Brak szablonow SMS.</td></tr>@endforelse
    </tbody></table></div>
    <div class="bg-white p-3">{{ $smsTemplates->links() }}</div>
    <div class="modal fade" id="smsTemplateModal" tabindex="-1" aria-labelledby="smsTemplateModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <form method="POST" action="{{ route('orders.sms-templates.store') }}" id="smsTemplateForm">
            @csrf<input type="hidden" name="_method" value="POST"><input type="hidden" name="expected_lock_version" value="0"><input type="hidden" name="editing_sms_template_id" value="">
            <div class="modal-header"><h2 class="modal-title fs-6" id="smsTemplateModalTitle">Szablon SMS</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
            <div class="modal-body">
                <label class="form-label" for="smsTemplateName">Nazwa wyswietlana</label><input class="form-control mb-3" name="name" id="smsTemplateName" maxlength="120" required>
                <label class="form-label" for="smsTemplateBody">Tresc SMS</label><textarea class="form-control mb-3" name="body_template" id="smsTemplateBody" rows="6" maxlength="12000" required></textarea>
                <label class="form-label" for="smsTemplateVariable">Wstaw zmienna OMS</label><select class="form-select mb-3" id="smsTemplateVariable"><option value="">Wybierz zmienna</option>@foreach($variableGroups as $group => $variables)<optgroup label="{{ $group }}">@foreach($variables as $variable)<option value="{{ $variable['token'] }}">{{ $variable['label'] }} - {{ $variable['token'] }}</option>@endforeach</optgroup>@endforeach</select>
                <div class="row g-2 align-items-end"><div class="col-sm-6"><label class="form-label" for="smsPreviewOrder">Numer zamowienia do podgladu</label><input class="form-control" type="number" min="1" id="smsPreviewOrder"></div><div class="col-sm-6"><button class="btn btn-outline-secondary" id="smsTemplatePreview" type="button"><i class="bi bi-eye me-1"></i>Podglad</button></div></div>
                <div class="small text-secondary mt-2" id="smsTemplateMetrics" role="status"></div><pre class="small mt-2 mb-0 text-wrap" id="smsTemplateRendered"></pre>
            </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary" type="submit">Zapisz</button></div>
        </form>
    </div></div></div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const templates = {{ Illuminate\Support\Js::from($smsTemplates->getCollection()->mapWithKeys(fn($template) => [$template->id => ['id'=>$template->id,'name'=>$template->name,'body'=>$template->body_template,'version'=>$template->lock_version,'url'=>route('orders.sms-templates.update',$template)]])) }};
        const form = document.getElementById('smsTemplateForm'), body = document.getElementById('smsTemplateBody');
        const open = template => {
            form.reset(); form.action = template?.url || @json(route('orders.sms-templates.store'));
            form.elements._method.value = template ? 'PATCH' : 'POST'; form.elements.expected_lock_version.value = template?.version ?? 0;
            form.elements.editing_sms_template_id.value = template?.id || '';
            form.elements.name.value = template?.name || ''; body.value = template?.body || '';
            document.getElementById('smsTemplateMetrics').textContent = ''; document.getElementById('smsTemplateRendered').textContent = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('smsTemplateModal')).show();
        };
        document.querySelector('[data-sms-template-create]').addEventListener('click', () => open(null));
        document.querySelectorAll('[data-sms-template-edit]').forEach(button => button.addEventListener('click', () => open(templates[button.dataset.smsTemplateEdit])));
        document.getElementById('smsTemplateVariable').addEventListener('change', event => {
            const token = event.target.value; if (!token) return;
            body.setRangeText(token, body.selectionStart, body.selectionEnd, 'end'); body.focus(); event.target.value = '';
        });
        let previewTimer, revision = 0;
        const preview = async () => {
            const currentRevision = ++revision;
            const output = document.getElementById('smsTemplateMetrics');
            try {
                const response = await fetch(@json(route('orders.sms.preview')), {method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':form.elements._token.value},body:JSON.stringify({body:body.value,order_id:document.getElementById('smsPreviewOrder').value || null})});
                const data = await response.json(); if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message);
                if (currentRevision !== revision) return;
                output.textContent = `${data.encoding} / ${data.units} jednostek / ${data.parts} czesci${data.rendered ? '' : ' (przed podstawieniem zmiennych)'}`;
                document.getElementById('smsTemplateRendered').textContent = data.body;
            } catch (error) { output.textContent = error.message || 'Nie mozna przygotowac podgladu.'; }
        };
        document.getElementById('smsTemplatePreview').addEventListener('click', preview);
        body.addEventListener('input', () => { clearTimeout(previewTimer); previewTimer = setTimeout(preview, 900); });
        @if($errors->smsTemplate->any())
            const oldId = @json((int) old('editing_sms_template_id', 0));
            open(oldId ? {...templates[oldId], id:oldId} : null);
            form.elements.name.value = @json(old('name', ''));
            body.value = @json(old('body_template', ''));
            form.elements.expected_lock_version.value = @json(old('expected_lock_version', 0));
        @endif
    });
    </script>
    @endif
</section>
