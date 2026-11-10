(() => {
    const page = document.querySelector('[data-custom-events]');
    if (!page) return;

    const modal = page.querySelector('#customEventModal');
    const title = modal.querySelector('#customEventModalTitle');
    const form = modal.querySelector('[data-custom-event-form]');
    const fields = form.elements;
    const submit = form.querySelector('[type="submit"]');
    const error = form.querySelector('[data-custom-event-error]');
    const listError = page.querySelector('[data-custom-event-list-error]');
    const buttonFields = form.querySelector('[data-custom-event-button-fields]');
    const pickers = Array.from(form.querySelectorAll('.custom-event-picker'));
    const statuses = Array.from(form.querySelectorAll('[name="order_statuses"]'));
    const selectedIcon = form.querySelector('[data-selected-event-icon]');
    const selectedColor = form.querySelector('[data-selected-event-color]');
    const previewIcon = form.querySelector('[data-event-preview-icon]');
    const previewSymbol = form.querySelector('[data-event-preview-symbol]');
    const previewLabel = form.querySelector('[data-event-preview-label]');
    const iconChoices = Array.from(form.querySelectorAll('[data-event-icon]'));
    const colorChoices = Array.from(form.querySelectorAll('[data-event-color]'));
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    let updateUrl, icon, color;

    const showError = (element, message) => {
        element.textContent = message;
        element.hidden = !message;
    };

    const updatePreview = () => {
        buttonFields.hidden = !fields.button_enabled.checked;
        selectedIcon.className = previewIcon.className = 'bi bi-' + icon;
        selectedColor.style.setProperty('--event-color', color);
        previewSymbol.style.setProperty('--event-color', color);
        previewSymbol.classList.toggle('text-dark', color === '#FFFFFF');
        previewLabel.textContent = fields.button_label.value.trim() || fields.name.value.trim();
        iconChoices.forEach(choice => choice.setAttribute('aria-pressed', choice.dataset.eventIcon === icon));
        colorChoices.forEach(choice => choice.setAttribute('aria-pressed', choice.dataset.eventColor === color));
    };

    modal.addEventListener('show.bs.modal', event => {
        form.reset();
        const trigger = event.relatedTarget;
        const data = trigger?.closest('[data-automation-sort-row]')?.dataset ?? {};
        const duplicating = trigger?.hasAttribute('data-custom-event-duplicate');
        updateUrl = trigger?.dataset.customEventUpdate;
        title.textContent = duplicating ? 'Nowe zdarzenie własne' : 'Ustawienia zdarzenia własnego';
        fields.name.value = duplicating
            ? Array.from('Kopia ' + data.name).slice(0, fields.name.maxLength).join('')
            : (data.name ?? '');
        fields.description.value = data.description ?? '';
        fields.button_enabled.checked = data.buttonEnabled === '1';
        fields.button_label.value = data.buttonLabel ?? '';
        fields.confirmation_required.checked = data.confirmation === '1';
        const enabledStatuses = JSON.parse(data.statuses ?? '[]');
        statuses.forEach(status => { status.checked = enabledStatuses.includes(status.value); });
        icon = data.icon || form.dataset.defaultIcon;
        color = data.color || form.dataset.defaultColor;
        pickers.forEach(picker => { picker.open = false; });
        showError(error, '');
        updatePreview();
    });
    modal.addEventListener('shown.bs.modal', () => fields.name.focus());

    form.addEventListener('input', updatePreview);
    pickers.forEach(picker => {
        picker.addEventListener('toggle', () => {
            if (picker.open) pickers.filter(other => other !== picker).forEach(other => { other.open = false; });
        });
    });
    form.addEventListener('click', event => {
        const choice = event.target.closest('[data-event-icon], [data-event-color]');
        if (!choice) {
            pickers.filter(picker => !picker.contains(event.target)).forEach(picker => { picker.open = false; });
            return;
        }
        icon = choice.dataset.eventIcon ?? icon;
        color = choice.dataset.eventColor ?? color;
        const picker = choice.closest('details');
        picker.open = false;
        picker.querySelector('summary').focus();
        updatePreview();
    });

    const send = async (url, method, data) => {
        const response = await fetch(url, { method, headers, body: data && JSON.stringify(data) });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.saved) {
            throw new Error(Object.values(result?.errors ?? {}).flat()[0] || result?.message || 'Nie udało się zapisać zmiany.');
        }
    };

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        submit.disabled = true;
        showError(error, '');
        try {
            await send(updateUrl || form.action, updateUrl ? 'PUT' : 'POST', {
                name: fields.name.value,
                description: fields.description.value,
                button_enabled: fields.button_enabled.checked,
                button_label: fields.button_label.value,
                confirmation_required: fields.confirmation_required.checked,
                order_statuses: statuses.filter(status => status.checked).map(status => status.value),
                icon,
                color,
            });
            window.location.reload();
        } catch (failure) {
            showError(error, failure.message);
            submit.disabled = false;
        }
    });

    page.addEventListener('click', async event => {
        const button = event.target.closest('[data-custom-event-delete]');
        if (!button || button.disabled || !window.confirm('Usunąć zdarzenie własne?')) return;
        showError(listError, '');
        button.disabled = true;
        try {
            await send(button.dataset.customEventDelete, 'DELETE');
            window.location.reload();
        } catch (failure) {
            showError(listError, failure.message);
            button.disabled = false;
        }
    });
})();
