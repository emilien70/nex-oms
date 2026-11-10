(() => {
    const page = document.querySelector('[data-automation-groups]');
    if (!page) return;

    const modal = page.querySelector('#automationGroupModal');
    const form = modal.querySelector('[data-group-form]');
    const name = form.querySelector('[name="name"]');
    const save = form.querySelector('[type="submit"]');
    const formError = form.querySelector('[data-group-form-error]');
    const listError = page.querySelector('[data-group-list-error]');
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    let updateUrl;

    const showError = (element, message) => {
        element.textContent = message;
        element.hidden = !message;
    };
    const send = async (url, method, data) => {
        const response = await fetch(url, {method, headers, body: data && JSON.stringify(data)});
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.saved) {
            throw new Error(Object.values(result?.errors ?? {}).flat()[0] || result?.message || 'Nie udało się zapisać zmiany.');
        }
    };

    modal.addEventListener('show.bs.modal', event => {
        const data = event.relatedTarget?.dataset ?? {};
        updateUrl = data.groupUpdate;
        name.value = data.groupName ?? '';
        showError(formError, '');
    });
    modal.addEventListener('shown.bs.modal', () => name.focus());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (save.disabled) return;
        save.disabled = true;
        showError(formError, '');
        try {
            await send(updateUrl || form.action, updateUrl ? 'PUT' : 'POST', {name: name.value});
            window.location.reload();
        } catch (failure) {
            showError(formError, failure.message);
            save.disabled = false;
        }
    });
    page.addEventListener('click', async event => {
        const button = event.target.closest('[data-group-delete]');
        if (!button || button.disabled) return;
        const {groupName, groupDelete, groupRuleCount} = button.dataset;
        const details = Number(groupRuleCount) > 0 ? '\nLiczba przypisanych reguł: ' + groupRuleCount
            + '. Reguły trafią do „Brak grupy” i zachowają swój stan.' : '';
        if (!window.confirm('Usunąć grupę „' + groupName + '”?' + details)) return;
        button.disabled = true;
        showError(listError, '');
        try {
            await send(groupDelete, 'DELETE');
            window.location.reload();
        } catch (failure) {
            showError(listError, failure.message);
            button.disabled = false;
        }
    });
})();
