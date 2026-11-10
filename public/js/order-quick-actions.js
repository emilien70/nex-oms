(() => {
    const card = document.querySelector('[data-order-quick-actions]');
    if (!card) return;

    const view = card.querySelector('[data-quick-action-view]');
    const editor = card.querySelector('[data-quick-action-layout]');
    const edit = card.querySelector('[data-quick-action-edit]');
    const save = card.querySelector('[data-quick-action-save]');
    const cancel = card.querySelector('[data-quick-action-cancel]');
    const grid = card.querySelector('[data-quick-action-slots]');
    const message = card.querySelector('[data-quick-action-message]');
    const slots = Array.from(grid.querySelectorAll('[data-quick-action-slot]'));
    const cells = Array.from(view.querySelectorAll('[data-quick-action-cell]'));
    const noActions = view.querySelector('[data-no-quick-actions]');
    const item = slot => slot.firstElementChild;
    const original = slots.map(item);
    const getLayout = items => items.flatMap((entry, index) => entry
        ? [{ id: Number(entry.dataset.quickActionItem), position: index + 1 }] : []);
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    let selected = null;
    let drag = null;
    let suppressClick = false;
    let running = false;

    const show = (text = '', danger = false) => {
        message.textContent = text;
        message.classList.toggle('text-danger', danger);
        message.classList.toggle('text-secondary', !danger);
        message.hidden = !text;
    };

    const send = async (url, method = 'GET', body) => {
        const response = await fetch(url, { method, headers, body: body && JSON.stringify(body) });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result) {
            const failure = new Error(Object.values(result?.errors ?? {}).flat()[0] || result?.message || 'Nie udało się wykonać żądania.');
            failure.status = response.status;
            throw failure;
        }
        return result;
    };

    const renderSlot = (slot, content) => {
        slot.replaceChildren(...(content ? [content] : []));
        slot.toggleAttribute('data-occupied', Boolean(content));
        slot.setAttribute('aria-label', 'Pole ' + slot.dataset.quickActionSlot
            + (content ? ': ' + content.dataset.actionLabel : ', puste'));
    };

    const selectSlot = slot => {
        selected?.setAttribute('aria-pressed', 'false');
        selected = slot;
        selected?.setAttribute('aria-pressed', 'true');
    };

    const swap = (from, to) => {
        const first = item(from);
        if (!first || from === to) return;
        renderSlot(from, item(to));
        renderSlot(to, first);
        selectSlot(null);
        to.focus({ preventScroll: true });
    };

    const editMode = enabled => {
        view.hidden = enabled;
        editor.hidden = !enabled;
        edit.setAttribute('aria-expanded', String(enabled));
        edit.disabled = enabled;
        selectSlot(null);
        show();
        if (enabled) (slots.find(item) ?? slots[0]).focus();
        else edit.focus();
    };
    edit.addEventListener('click', () => editMode(true));
    cancel.addEventListener('click', () => {
        slots.forEach(slot => renderSlot(slot, null));
        slots.forEach((slot, index) => renderSlot(slot, original[index]));
        editMode(false);
    });
    save.addEventListener('click', async () => {
        if (save.disabled) return;
        const layout = getLayout(slots.map(item));
        const previous = getLayout(original);
        if (JSON.stringify(layout) === JSON.stringify(previous)) {
            editMode(false);
            return;
        }
        save.disabled = cancel.disabled = true;
        grid.inert = true;
        show();
        try {
            await send(card.dataset.layoutUrl, 'PATCH', { layout, previous_layout: previous });
            window.location.reload();
        } catch (failure) {
            show(failure.message, true);
            grid.inert = false;
            save.disabled = cancel.disabled = false;
        }
    });

    grid.addEventListener('click', event => {
        const slot = event.target.closest('[data-quick-action-slot]');
        if (!slot || suppressClick) return;
        if (selected && selected !== slot) swap(selected, slot);
        else selectSlot(!selected && item(slot) ? slot : null);
    });
    grid.addEventListener('keydown', event => {
        const slot = event.target.closest('[data-quick-action-slot]');
        if (!slot || !item(slot) || event.altKey || event.ctrlKey || event.metaKey) return;
        const columns = getComputedStyle(grid).gridTemplateColumns.split(' ').length;
        const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -columns, ArrowDown: columns }[event.key];
        if (!delta) return;
        event.preventDefault();
        const target = slots[slots.indexOf(slot) + delta];
        if (target) swap(slot, target);
    });
    grid.addEventListener('pointerdown', event => {
        const slot = event.target.closest('[data-quick-action-slot]');
        if (!slot || !item(slot) || !event.isPrimary || event.button !== 0) return;
        drag = { slot, id: event.pointerId, x: event.clientX, y: event.clientY };
        slot.setPointerCapture(event.pointerId);
    });
    grid.addEventListener('pointermove', event => {
        if (drag?.id !== event.pointerId) return;
        if (!drag.moved && Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < 5) return;
        drag.moved = true;
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-quick-action-slot]');
        drag.target?.classList.remove('is-drop-target');
        drag.target = target && grid.contains(target) ? target : null;
        drag.target?.classList.add('is-drop-target');
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => grid.addEventListener(type, event => {
        if (drag?.id !== event.pointerId) return;
        const { slot, target, moved } = drag;
        drag = null;
        target?.classList.remove('is-drop-target');
        if (!moved) return;
        suppressClick = true;
        window.setTimeout(() => { suppressClick = false; });
        if (type === 'pointerup' && target) swap(slot, target);
    }));

    const requestIds = new WeakMap();
    document.addEventListener('nexoms:order-status', event => {
        const allowsStatus = element => {
            const statuses = JSON.parse(element.dataset.actionStatuses);
            return statuses.length === 0 || statuses.includes(event.detail);
        };
        let lastAvailable = -1;
        cells.forEach((cell, index) => {
            const button = cell.querySelector('[data-quick-action-run]');
            const available = button && allowsStatus(button);
            if (button) button.hidden = !available;
            cell.classList.toggle('quick-action-gap', !available);
            if (available) lastAvailable = index;
        });
        cells.forEach((cell, index) => { cell.hidden = index > lastAvailable; });
        noActions.hidden = lastAvailable !== -1;
        original.filter(Boolean).forEach(element => {
            element.toggleAttribute('data-unavailable', !allowsStatus(element));
        });
    });

    view.addEventListener('click', async event => {
        const button = event.target.closest('[data-quick-action-run]');
        if (!button || running) return;
        const confirmed = button.hasAttribute('data-requires-confirmation');
        if (confirmed && !requestIds.has(button) && !window.confirm('Uruchomić akcję „' + button.dataset.actionLabel + '”?')) return;
        running = true;
        button.disabled = edit.disabled = true;
        show('Sprawdzanie reguł...');

        try {
            const requestId = requestIds.get(button) || crypto.randomUUID();
            requestIds.set(button, requestId);
            let result = await send(button.dataset.quickActionRun, 'POST', { request_id: requestId, confirmed });
            for (let attempt = 0; result.status === 'pending' && attempt < 18; attempt++) {
                await new Promise(resolve => window.setTimeout(resolve, attempt < 4 ? 1000 : 2000));
                result = await send(result.status_url);
            }
            if (result.status === 'pending') {
                show('OMS nadal sprawdza reguły. Kliknij ponownie, aby sprawdzić wynik tego samego zlecenia.');
            } else {
                requestIds.delete(button);
                show(result.message, ['failed', 'unavailable'].includes(result.status));
                document.dispatchEvent(new Event('nexoms:automation-wake'));
            }
        } catch (failure) {
            if (failure.status >= 400 && failure.status < 500) requestIds.delete(button);
            show(failure.message, true);
        } finally {
            running = false;
            button.disabled = edit.disabled = false;
        }
    });
})();
