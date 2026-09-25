<style>
    .email-templates-content {
        padding: 34px 18px 24px;
    }

    .email-templates-notice {
        align-items: flex-start;
        background: #f8fafc;
        border: 1px solid #d6dce3;
        border-left: 3px solid #0d6efd;
        border-radius: 7px;
        color: #4b5563;
        display: flex;
        font-size: 12px;
        gap: 14px;
        line-height: 1.55;
        margin-bottom: 20px;
        padding: 14px 16px;
    }

    .email-templates-notice i {
        color: #526174;
        flex: 0 0 auto;
        font-size: 15px;
        margin-top: 1px;
    }

    .email-templates-toolbar {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 18px;
    }

    .email-template-add {
        align-items: center;
        border-radius: 22px;
        display: inline-flex;
        font-size: 13px;
        font-weight: 600;
        gap: 8px;
        padding: 7px 15px 7px 8px;
    }

    .email-template-add-icon {
        align-items: center;
        border: 1px solid currentColor;
        border-radius: 50%;
        display: inline-flex;
        font-size: 18px;
        height: 29px;
        justify-content: center;
        line-height: 1;
        width: 29px;
    }

    .email-templates-table-wrap {
        background: #ffffff;
        border: 1px solid #dfe4ea;
        border-radius: 6px;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .08);
        overflow: hidden;
    }

    .email-templates-table {
        font-size: 12px;
        margin: 0;
        table-layout: fixed;
        width: 100%;
    }

    .email-templates-table th {
        background: #087fd8;
        border-color: #087fd8;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        padding: 13px 12px;
        text-transform: uppercase;
    }

    .email-templates-table td {
        border-bottom: 1px solid #e5e9ee;
        color: #526174;
        padding: 9px 12px;
        vertical-align: middle;
    }

    .email-templates-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .email-template-name {
        color: #304b68;
        font-weight: 600;
    }

    .email-template-preview {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .email-template-actions {
        align-items: center;
        display: flex;
        gap: 7px;
        justify-content: flex-end;
    }

    .email-template-icon-button {
        align-items: center;
        background: #ffffff;
        border: 1px solid #edf0f3;
        border-radius: 50%;
        color: #31506d;
        display: inline-flex;
        height: 30px;
        justify-content: center;
        padding: 0;
        width: 30px;
    }

    .email-template-icon-button:hover {
        background: #f3f7fa;
        border-color: #d8e0e7;
        color: #0d6efd;
    }

    .email-templates-empty {
        color: #6b7280;
        padding: 42px 20px;
        text-align: center;
    }

    .email-templates-footer {
        align-items: center;
        color: #6b7280;
        display: flex;
        font-size: 12px;
        justify-content: space-between;
        padding: 12px;
    }

    .email-template-modal .modal-content {
        border: 0;
        border-radius: 8px;
    }

    .email-template-modal .modal-header,
    .email-template-modal .modal-footer {
        border: 0;
        padding: 20px 30px;
    }

    .email-template-modal .modal-body {
        padding: 8px 30px 24px;
    }

    .email-template-modal .form-label,
    .email-template-modal .form-check-label {
        color: #526174;
        font-size: 12px;
    }

    .email-template-modal .form-control,
    .email-template-modal .form-select {
        font-size: 13px;
    }

    .email-template-editor {
        align-items: stretch;
        display: grid;
        gap: 18px;
        grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
    }

    .email-template-variables {
        border: 1px solid #dce2e8;
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        max-height: 480px;
        min-height: 360px;
        overflow: hidden;
    }

    .email-template-variables-header {
        border-bottom: 1px solid #e5e9ee;
        padding: 12px;
    }

    .email-template-variables-title {
        color: #374151;
        font-size: 11px;
        margin-bottom: 9px;
    }

    .email-template-variables-list {
        overflow-y: auto;
        padding: 10px 12px;
    }

    .email-template-variable-group + .email-template-variable-group {
        border-top: 1px solid #edf0f3;
        margin-top: 9px;
        padding-top: 9px;
    }

    .email-template-variable-group-title {
        color: #1f2937;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .email-template-variable {
        background: transparent;
        border: 0;
        color: #31506d;
        display: block;
        font-size: 11px;
        overflow-wrap: anywhere;
        padding: 3px 0;
        text-align: left;
        width: 100%;
    }

    .email-template-variable:hover {
        color: #0d6efd;
        text-decoration: underline;
    }

    .email-template-body-column {
        display: flex;
        flex-direction: column;
        min-height: 480px;
    }

    .email-template-body-column .email-template-body {
        flex: 1 1 auto;
        min-height: 440px !important;
        resize: vertical;
    }

    .email-template-attachment-details {
        background: #f8fafc;
        border: 1px solid #e3e8ee;
        border-radius: 6px;
        margin-top: 8px;
        padding: 12px;
    }

    .email-template-existing-file {
        color: #526174;
        font-size: 11px;
        margin-top: 6px;
    }

    @media (max-width: 991.98px) {
        .email-template-editor {
            grid-template-columns: 1fr;
        }

        .email-template-variables {
            max-height: 280px;
            min-height: 220px;
        }

        .email-template-body-column {
            min-height: 360px;
        }

        .email-template-body-column .email-template-body {
            min-height: 320px !important;
        }
    }

    @media (max-width: 767.98px) {
        .email-templates-content {
            padding: 20px 10px;
        }

        .email-templates-table {
            min-width: 900px;
        }

        .email-templates-table-wrap {
            overflow-x: auto;
        }

        .email-template-modal .modal-header,
        .email-template-modal .modal-footer,
        .email-template-modal .modal-body {
            padding-left: 18px;
            padding-right: 18px;
        }
    }
</style>

<div class="email-templates-content">
    @if (session('error'))
        <div class="alert alert-danger py-2 small" role="alert">{{ session('error') }}</div>
    @endif

    <div class="email-templates-notice" role="note">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            Szablony e-mail są gotowymi wzorami wiadomości do klientów. Znaczniki umieszczone w temacie
            i treści zostaną zastąpione danymi konkretnego zamówienia podczas wysyłki.
        </div>
    </div>

    <div class="email-templates-toolbar">
        <button
            class="btn btn-success email-template-add"
            type="button"
            data-email-template-create
            @disabled($accounts->isEmpty())
            @if ($accounts->isEmpty()) title="Najpierw dodaj konto e-mail" @endif
        >
            <span class="email-template-add-icon" aria-hidden="true">+</span>
            Nowy szablon e-mail
        </button>
    </div>

    @if ($accounts->isEmpty())
        <div class="alert alert-warning small" role="alert">
            Przed utworzeniem szablonu dodaj co najmniej jedno konto w zakładce
            <a href="{{ route('orders.email-accounts.index') }}">Konta E-Mail</a>.
        </div>
    @endif

    <div class="email-templates-table-wrap">
        @if ($templates->isEmpty())
            <div class="email-templates-empty">Nie utworzono jeszcze żadnego szablonu e-mail.</div>
        @else
            <table class="table email-templates-table">
                <colgroup>
                    <col style="width: 20%">
                    <col style="width: 25%">
                    <col style="width: 30%">
                    <col style="width: 15%">
                    <col style="width: 10%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Nazwa wyświetlana</th>
                        <th scope="col">Temat wiadomości</th>
                        <th scope="col">Treść wiadomości (początek)</th>
                        <th scope="col">Konto e-mail</th>
                        <th class="text-end" scope="col">Operacje</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($templates as $template)
                        @php
                            $bodyPreview = trim(preg_replace('/\s+/', ' ', strip_tags($template->body_template)) ?? '');
                        @endphp
                        <tr>
                            <td>
                                <div class="email-template-name">{{ $template->name }}</div>
                                @if ($template->is_hidden)
                                    <span class="badge text-bg-secondary mt-1">Ukryty</span>
                                @endif
                            </td>
                            <td>{{ $template->subject_template }}</td>
                            <td><div class="email-template-preview" title="{{ $bodyPreview }}">{{ \Illuminate\Support\Str::limit($bodyPreview, 130) }}</div></td>
                            <td>
                                @if ($template->emailAccount !== null)
                                    <div>{{ $template->emailAccount->email }}</div>
                                    <div class="text-muted">{{ $template->emailAccount->name }}</div>
                                @else
                                    <span class="text-danger">Brak konta</span>
                                @endif
                            </td>
                            <td>
                                <div class="email-template-actions">
                                    <button
                                        class="email-template-icon-button"
                                        type="button"
                                        data-email-template-edit="{{ $template->getKey() }}"
                                        title="Edytuj szablon"
                                        aria-label="Edytuj szablon {{ $template->name }}"
                                    ><i class="bi bi-pencil" aria-hidden="true"></i></button>

                                    <form method="POST" action="{{ route('orders.email-templates.duplicate', $template) }}">
                                        @csrf
                                        <button
                                            class="email-template-icon-button"
                                            type="submit"
                                            title="Kopiuj szablon"
                                            aria-label="Kopiuj szablon {{ $template->name }}"
                                        ><i class="bi bi-copy" aria-hidden="true"></i></button>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route('orders.email-templates.destroy', $template) }}"
                                        onsubmit="return confirm('Usunąć ten szablon e-mail?')"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            class="email-template-icon-button"
                                            type="submit"
                                            title="Usuń szablon"
                                            aria-label="Usuń szablon {{ $template->name }}"
                                        ><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="email-templates-footer">
                <span>{{ $templates->firstItem() }}-{{ $templates->lastItem() }} z {{ $templates->total() }} pozycji</span>
                @if ($templates->hasPages())
                    {{ $templates->onEachSide(1)->links() }}
                @endif
            </div>
        @endif
    </div>
</div>

<div class="modal fade email-template-modal" id="emailTemplateModal" tabindex="-1" aria-labelledby="emailTemplateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form
            class="modal-content"
            method="POST"
            action="{{ route('orders.email-templates.store') }}"
            enctype="multipart/form-data"
            data-email-template-form
            data-store-action="{{ route('orders.email-templates.store') }}"
            data-update-action="{{ url('/orders/email-templates/__TEMPLATE__') }}"
        >
                @csrf
                <input type="hidden" name="_method" value="PATCH" data-email-template-method disabled>
                <input type="hidden" name="email_template_id" value="" data-email-template-id>

                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="emailTemplateModalTitle">Szablon e-mail</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <div class="row align-items-start mb-3">
                        <label class="col-md-3 col-form-label text-md-end" for="emailTemplateName">Nazwa wyświetlana</label>
                        <div class="col-md-9">
                            <input class="form-control @error('name') is-invalid @enderror" id="emailTemplateName" name="name" required maxlength="160">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row align-items-start mb-3">
                        <label class="col-md-3 col-form-label text-md-end" for="emailTemplateSubject">Temat wiadomości</label>
                        <div class="col-md-9">
                            <input class="form-control @error('subject_template') is-invalid @enderror" id="emailTemplateSubject" name="subject_template" required maxlength="255" data-template-insertion-target>
                            @error('subject_template')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row align-items-start mb-3">
                        <span class="col-md-3 col-form-label text-md-end">Format</span>
                        <div class="col-md-9 d-flex flex-wrap gap-4 pt-2">
                            @foreach ($formatOptions as $option)
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        id="emailTemplateFormat{{ $loop->index }}"
                                        name="format"
                                        type="radio"
                                        value="{{ $option->value }}"
                                        @checked($option === \App\Enums\EmailTemplateFormat::PlainText)
                                    >
                                    <label class="form-check-label" for="emailTemplateFormat{{ $loop->index }}">{{ $option->label() }}</label>
                                </div>
                            @endforeach
                            @error('format')<div class="text-danger small w-100">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="email-template-editor mb-3">
                        <aside class="email-template-variables" aria-label="Dostępne zmienne zamówienia">
                            <div class="email-template-variables-header">
                                <div class="email-template-variables-title">Kliknij zmienną, aby wstawić ją do aktywnego pola.</div>
                                <label class="visually-hidden" for="emailTemplateVariableSearch">Szukaj zmiennej</label>
                                <input class="form-control form-control-sm" id="emailTemplateVariableSearch" type="search" placeholder="Szukaj..." data-variable-search>
                            </div>
                            <div class="email-template-variables-list">
                                @foreach ($variableGroups as $group => $definitions)
                                    <section class="email-template-variable-group" data-variable-group>
                                        <div class="email-template-variable-group-title">{{ $group }}</div>
                                        @foreach ($definitions as $definition)
                                            <button
                                                class="email-template-variable"
                                                type="button"
                                                data-variable-token="{{ $definition['token'] }}"
                                                data-variable-search-text="{{ \Illuminate\Support\Str::lower($definition['token'].' '.$definition['label']) }}"
                                                title="{{ $definition['description'] }}"
                                            >{{ $definition['token'] }}</button>
                                        @endforeach
                                    </section>
                                @endforeach
                            </div>
                        </aside>

                        <div class="email-template-body-column">
                            <label class="form-label" for="emailTemplateBody">Treść wiadomości</label>
                            <textarea class="form-control email-template-body @error('body_template') is-invalid @enderror" id="emailTemplateBody" name="body_template" required maxlength="25000" data-template-insertion-target></textarea>
                            @error('body_template')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row align-items-start mb-3">
                        <label class="col-md-3 col-form-label text-md-end" for="emailTemplateAccount">Konto e-mail</label>
                        <div class="col-md-9">
                            <select class="form-select @error('email_account_id') is-invalid @enderror" id="emailTemplateAccount" name="email_account_id" required>
                                <option value="">Wybierz konto</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->getKey() }}">{{ $account->email }} - {{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error('email_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row align-items-center mb-3">
                        <span class="col-md-3 col-form-label text-md-end">Widoczność</span>
                        <div class="col-md-9">
                            <div class="form-check form-switch">
                                <input class="form-check-input" id="emailTemplateHidden" name="is_hidden" type="checkbox" value="1">
                                <label class="form-check-label" for="emailTemplateHidden">Ukryj szablon na liście ręcznego wyboru</label>
                            </div>
                        </div>
                    </div>

                    @for ($slot = 1; $slot <= 2; $slot++)
                        @php
                            $typeField = "attachment_{$slot}_type";
                            $documentField = "attachment_{$slot}_document_type";
                            $fileField = "attachment_{$slot}_file";
                        @endphp
                        <div class="row align-items-start mb-3">
                            <label class="col-md-3 col-form-label text-md-end" for="emailTemplateAttachmentType{{ $slot }}">
                                Załącznik {{ $slot }}
                            </label>
                            <div class="col-md-9">
                                <select
                                    class="form-select {{ $errors->has($typeField) ? 'is-invalid' : '' }}"
                                    id="emailTemplateAttachmentType{{ $slot }}"
                                    name="{{ $typeField }}"
                                    data-attachment-type="{{ $slot }}"
                                >
                                    @foreach ($attachmentTypeOptions as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                                @if ($errors->has($typeField))
                                    <div class="invalid-feedback">{{ $errors->first($typeField) }}</div>
                                @endif

                                <div class="email-template-attachment-details" data-attachment-upload="{{ $slot }}" hidden>
                                    <label class="form-label" for="emailTemplateAttachmentFile{{ $slot }}">Plik z dysku</label>
                                    <input
                                        class="form-control {{ $errors->has($fileField) ? 'is-invalid' : '' }}"
                                        id="emailTemplateAttachmentFile{{ $slot }}"
                                        name="{{ $fileField }}"
                                        type="file"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.xml,.jpg,.jpeg,.png,.zip"
                                        data-attachment-file="{{ $slot }}"
                                    >
                                    @if ($errors->has($fileField))
                                        <div class="invalid-feedback">{{ $errors->first($fileField) }}</div>
                                    @endif
                                    <div class="email-template-existing-file" data-attachment-existing-file="{{ $slot }}" hidden></div>
                                </div>

                                <div class="email-template-attachment-details" data-attachment-document="{{ $slot }}" hidden>
                                    <label class="form-label" for="emailTemplateAttachmentDocument{{ $slot }}">Dokument</label>
                                    <select
                                        class="form-select {{ $errors->has($documentField) ? 'is-invalid' : '' }}"
                                        id="emailTemplateAttachmentDocument{{ $slot }}"
                                        name="{{ $documentField }}"
                                        data-attachment-document-select="{{ $slot }}"
                                    >
                                        <option value="">Wybierz dokument</option>
                                        @foreach ($documentTypeOptions as $option)
                                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                        @endforeach
                                    </select>
                                    @if ($errors->has($documentField))
                                        <div class="invalid-feedback">{{ $errors->first($documentField) }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endfor
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary px-4" type="submit">Zapisz</button>
                    <button class="btn btn-light border px-4" type="button" data-bs-dismiss="modal">Zamknij</button>
                </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modalElement = document.getElementById('emailTemplateModal');
        const form = modalElement?.querySelector('[data-email-template-form]');

        if (!modalElement || !form) {
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const templates = {{ Illuminate\Support\Js::from($templateFormData) }};
        const oldForm = {{ Illuminate\Support\Js::from([
            'id' => old('email_template_id'),
            'email_account_id' => old('email_account_id'),
            'name' => old('name'),
            'subject_template' => old('subject_template'),
            'format' => old('format'),
            'body_template' => old('body_template'),
            'is_hidden' => old('is_hidden'),
            'attachment_1_type' => old('attachment_1_type'),
            'attachment_1_document_type' => old('attachment_1_document_type'),
            'attachment_2_type' => old('attachment_2_type'),
            'attachment_2_document_type' => old('attachment_2_document_type'),
        ]) }};
        const hasValidationErrors = {{ Illuminate\Support\Js::from($errors->any()) }};
        const requestedEditId = {{ Illuminate\Support\Js::from(request()->query('edit')) }};
        const methodInput = form.querySelector('[data-email-template-method]');
        const idInput = form.querySelector('[data-email-template-id]');
        const subjectInput = form.elements.namedItem('subject_template');
        const bodyInput = form.elements.namedItem('body_template');
        const hiddenInput = form.elements.namedItem('is_hidden');
        const searchInput = form.querySelector('[data-variable-search]');
        const attachmentSlots = [1, 2];
        let insertionTarget = bodyInput;

        function updateAttachmentFields(slot) {
            const typeInput = form.querySelector(`[data-attachment-type="${slot}"]`);
            const uploadPanel = form.querySelector(`[data-attachment-upload="${slot}"]`);
            const documentPanel = form.querySelector(`[data-attachment-document="${slot}"]`);
            const fileInput = form.querySelector(`[data-attachment-file="${slot}"]`);
            const documentInput = form.querySelector(`[data-attachment-document-select="${slot}"]`);
            const existingFile = form.querySelector(`[data-attachment-existing-file="${slot}"]`);
            const isUpload = typeInput.value === 'upload';
            const isDocument = typeInput.value === 'document';
            const existingName = existingFile.dataset.filename || '';

            uploadPanel.hidden = !isUpload;
            documentPanel.hidden = !isDocument;
            fileInput.required = isUpload && existingName === '';
            documentInput.required = isDocument;
        }

        function openForm(template = null, overrides = null) {
            const editing = template !== null;
            const values = {
                ...(template || {}),
                ...(overrides || {}),
            };

            form.action = editing
                ? form.dataset.updateAction.replace('__TEMPLATE__', template.id)
                : form.dataset.storeAction;
            methodInput.disabled = !editing;
            idInput.value = editing ? template.id : '';
            form.elements.namedItem('name').value = values.name ?? '';
            subjectInput.value = values.subject_template ?? '';
            bodyInput.value = values.body_template ?? '';
            form.elements.namedItem('email_account_id').value = values.email_account_id ?? '';
            hiddenInput.checked = values.is_hidden === true || values.is_hidden === '1' || values.is_hidden === 1;

            const format = values.format ?? 'plain_text';
            form.querySelectorAll('[name="format"]').forEach((input) => {
                input.checked = input.value === format;
            });

            attachmentSlots.forEach((slot) => {
                const typeInput = form.querySelector(`[data-attachment-type="${slot}"]`);
                const documentInput = form.querySelector(`[data-attachment-document-select="${slot}"]`);
                const fileInput = form.querySelector(`[data-attachment-file="${slot}"]`);
                const existingFile = form.querySelector(`[data-attachment-existing-file="${slot}"]`);
                const existingName = values[`attachment_${slot}_original_name`] ?? '';

                typeInput.value = values[`attachment_${slot}_type`] ?? 'none';
                documentInput.value = values[`attachment_${slot}_document_type`] ?? '';
                fileInput.value = '';
                existingFile.dataset.filename = existingName;
                existingFile.textContent = existingName === ''
                    ? ''
                    : `Zapisany plik: ${existingName}. Wybierz nowy tylko, aby go zastąpić.`;
                existingFile.hidden = existingName === '';
                updateAttachmentFields(slot);
            });

            insertionTarget = bodyInput;
            modal.show();
        }

        document.querySelector('[data-email-template-create]')?.addEventListener('click', () => openForm());

        document.querySelectorAll('[data-email-template-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                const template = templates[String(button.dataset.emailTemplateEdit)];

                if (template) {
                    openForm(template);
                }
            });
        });

        attachmentSlots.forEach((slot) => {
            form.querySelector(`[data-attachment-type="${slot}"]`)?.addEventListener('change', () => {
                updateAttachmentFields(slot);
            });
        });

        form.querySelectorAll('[data-template-insertion-target]').forEach((input) => {
            input.addEventListener('focus', () => {
                insertionTarget = input;
            });
        });

        form.querySelectorAll('[data-variable-token]').forEach((button) => {
            button.addEventListener('click', () => {
                const token = button.dataset.variableToken;
                const start = insertionTarget.selectionStart ?? insertionTarget.value.length;
                const end = insertionTarget.selectionEnd ?? start;

                insertionTarget.setRangeText(token, start, end, 'end');
                insertionTarget.focus();
            });
        });

        searchInput?.addEventListener('input', () => {
            const query = searchInput.value.trim().toLocaleLowerCase('pl');

            form.querySelectorAll('[data-variable-group]').forEach((group) => {
                let visible = 0;

                group.querySelectorAll('[data-variable-token]').forEach((button) => {
                    const matches = query === '' || button.dataset.variableSearchText.includes(query);
                    button.hidden = !matches;
                    visible += matches ? 1 : 0;
                });

                group.hidden = visible === 0;
            });
        });

        if (hasValidationErrors) {
            const template = oldForm.id ? templates[String(oldForm.id)] : null;
            openForm(template || null, oldForm);
        } else if (requestedEditId && templates[String(requestedEditId)]) {
            openForm(templates[String(requestedEditId)]);
        }
    });
</script>
