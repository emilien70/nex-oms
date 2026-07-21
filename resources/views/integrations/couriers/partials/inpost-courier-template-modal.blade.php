@php
    $templateErrors = $errors->getBag('parcelTemplate');
    $templateModalOpen = $templateErrors->any();
@endphp

<div class="modal fade inpost-modal" id="inpostCourierTemplateModal" tabindex="-1" aria-labelledby="inpostCourierTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered courier-template-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="inpostCourierTemplateModalLabel" data-template-modal-title>Nowy szablon przesy&#322;ki</h2>
                    <div class="small text-secondary">Wymiary podawane s&#261; w centymetrach, a waga w kilogramach.</div>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <form
                method="POST"
                action="{{ route('integrations.couriers.inpost-courier.templates.store') }}"
                data-courier-template-form
                data-store-url="{{ route('integrations.couriers.inpost-courier.templates.store') }}"
                data-update-url="{{ route('integrations.couriers.inpost-courier.templates.update', ['templateId' => '__TEMPLATE_ID__']) }}"
            >
                @csrf
                <input type="hidden" name="_method" value="PUT" disabled data-template-method>
                <input type="hidden" name="_template_id" value="{{ old('_template_id') }}" data-template-id-input>
                <div class="modal-body">
                    @if ($templateErrors->any())
                        <div class="alert alert-danger py-2 px-3 small">Popraw dane szablonu oznaczone na czerwono.</div>
                    @endif
                    <div class="row g-3">
                        <div class="col-12 courier-template-name-field">
                            <label class="form-label" for="courier_template_name">Nazwa szablonu</label>
                            <input id="courier_template_name" class="form-control form-control-sm @error('template_name', 'parcelTemplate') is-invalid @enderror" name="template_name" maxlength="100" value="{{ old('template_name') }}" required data-template-field="name">
                            @error('template_name', 'parcelTemplate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="courier-template-dimensions">
                                @foreach (['weight' => ['Waga (kg)', 50], 'length' => ['D&#322;ugo&#347;&#263; (cm)', 350], 'width' => ['Szeroko&#347;&#263; (cm)', 350], 'height' => ['Wysoko&#347;&#263; (cm)', 350]] as $field => [$label, $max])
                                    <div class="courier-template-dimension-field">
                                        <label class="form-label" for="courier_template_{{ $field }}">{!! $label !!}</label>
                                        <input id="courier_template_{{ $field }}" class="form-control form-control-sm @error('template_'.$field, 'parcelTemplate') is-invalid @enderror" type="number" name="template_{{ $field }}" min="0.01" max="{{ $max }}" step="0.01" value="{{ old('template_'.$field) }}" required data-template-field="{{ $field }}">
                                        @error('template_'.$field, 'parcelTemplate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button>
                    <button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($templateModalOpen)
    <script>window.nexOmsOpenCourierTemplateModal = true;</script>
@endif
