(() => {
    const card = document.querySelector('[data-order-quick-actions]');
    if (!card) return;

    const view = card.querySelector('[data-quick-action-view]');
    const editor = card.querySelector('[data-quick-action-layout]');
    const edit = card.querySelector('[data-quick-action-edit]');
    const save = card.querySelector('[data-quick-action-save]');
    const cancel = card.querySelector('[data-quick-action-cancel]');
    const columns = card.querySelector('[data-quick-action-columns]');
    const runList = card.querySelector('[data-quick-action-runs]');
    const list = card.querySelector('[data-quick-action-items]');
    const layoutError = card.querySelector('[data-quick-action-layout-error]');
    const status = card.querySelector('[data-quick-action-status]');
    const runButtons = Array.from(view.querySelectorAll('[data-quick-action-run]'));
    const noActions = view.querySelector('[data-no-quick-actions]');
    const items = () => Array.from(list.querySelectorAll('[data-quick-action-item]'));
    const original = items();
    const previous = original.map(button => ({ id: Number(button.dataset.quickActionItem), position: Number(button.dataset.actionPosition) }));
    const previousMaxColumns = Number(card.dataset.maxColumns);
    const selectedMaxColumns = () => Number(columns.querySelector('input:checked').value);
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    };
    let selected = null;
    let drag = null;
    let suppressClick = false;
    let running = false;

    const limitRows = (container, buttons, limit) => {
        const width = container.getBoundingClientRect().width;
        if (!width) return;

        // Flex handles width-based wrapping. Insert a break only when the count is the limiting factor.
        const gap = parseFloat(getComputedStyle(container).columnGap);
        const breaks = [];
        let usedWidth = 0;
        let count = 0;
        for (const button of buttons) {
            if (button.hidden) continue;
            const buttonWidth = button.getBoundingClientRect().width;
            const nextWidth = usedWidth + gap + buttonWidth;
            if (count && nextWidth > width) {
                count = 0;
            } else if (count === limit) {
                breaks.push(button);
                count = 0;
            }
            usedWidth = count ? nextWidth : buttonWidth;
            count++;
        }

        const markers = Array.from(container.querySelectorAll('.quick-action-line-break'));
        if (markers.length === breaks.length && markers.every((marker, i) => marker.nextElementSibling === breaks[i])) return;
        markers.forEach(marker => marker.remove());
        breaks.forEach(button => {
            const marker = document.createElement('span');
            marker.className = 'quick-action-line-break';
            marker.setAttribute('aria-hidden', 'true');
            button.before(marker);
        });
    };

    const refreshRows = () => {
        if (editor.hidden) limitRows(runList, runButtons, previousMaxColumns);
        else limitRows(list, items(), selectedMaxColumns());
    };

    let frame = null;
    const sizes = new ResizeObserver(() => {
        if (frame !== null) return;
        frame = requestAnimationFrame(() => {
            frame = null;
            refreshRows();
        });
    });
    [runList, list, ...runButtons, ...original].forEach(element => sizes.observe(element));
    columns.addEventListener('change', refreshRows);
    refreshRows();

    const showLayoutError = (text = '') => {
        layoutError.textContent = text;
        layoutError.hidden = !text;
    };
    const notify = (message, tone = 'error') => window.nexOmsShowActivity?.({ tone, title: 'Szybkie akcje', message });

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

    const selectItem = button => {
        selected?.setAttribute('aria-pressed', 'false');
        selected = button;
        selected?.setAttribute('aria-pressed', 'true');
    };

    const move = (button, target, after) => {
        list.insertBefore(button, after ? target.nextElementSibling : target);
        refreshRows();
        selectItem(null);
        button.focus({ preventScroll: true });
        status.textContent = `Pozycja ${items().indexOf(button) + 1} z ${original.length}.`;
    };

    const editMode = enabled => {
        view.hidden = enabled;
        editor.hidden = !enabled;
        edit.setAttribute('aria-expanded', String(enabled));
        edit.disabled = enabled;
        selectItem(null);
        status.textContent = '';
        showLayoutError();
        refreshRows();
        if (enabled) columns.querySelector('input:checked').focus();
        else edit.focus();
    };
    edit.addEventListener('click', () => editMode(true));
    cancel.addEventListener('click', () => {
        list.replaceChildren(...original);
        columns.querySelector(`input[value="${previousMaxColumns}"]`).checked = true;
        editMode(false);
    });
    save.addEventListener('click', async () => {
        if (save.disabled) return;
        const current = items();
        const maxColumns = selectedMaxColumns();
        const reordered = current.some((button, index) => button !== original[index]);
        if (!reordered && maxColumns === previousMaxColumns) {
            editMode(false);
            return;
        }
        const layout = reordered
            ? current.map((button, index) => ({ id: Number(button.dataset.quickActionItem), position: index + 1 }))
            : previous;
        save.disabled = cancel.disabled = columns.disabled = true;
        list.inert = true;
        showLayoutError();
        try {
            await send(card.dataset.layoutUrl, 'PATCH', {
                layout, previous_layout: previous, max_columns: maxColumns, previous_max_columns: previousMaxColumns,
            });
            window.location.reload();
        } catch (failure) {
            showLayoutError(failure.message);
            list.inert = false;
            save.disabled = cancel.disabled = columns.disabled = false;
        }
    });

    list.addEventListener('click', event => {
        const button = event.target.closest('[data-quick-action-item]');
        if (!button || suppressClick) return;
        if (selected && selected !== button) {
            const order = items();
            move(selected, button, order.indexOf(selected) < order.indexOf(button));
        } else {
            selectItem(selected ? null : button);
        }
    });
    list.addEventListener('keydown', event => {
        const button = event.target.closest('[data-quick-action-item]');
        if (!button || event.altKey || event.ctrlKey || event.metaKey) return;
        const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -1, ArrowDown: 1 }[event.key];
        if (!delta) return;
        event.preventDefault();
        const order = items();
        const target = order[order.indexOf(button) + delta];
        if (target) move(button, target, delta > 0);
    });

    const dropAt = (x, y, source) => {
        const bounds = list.getBoundingClientRect();
        if (x < bounds.left || x > bounds.right || y < bounds.top || y > bounds.bottom) return null;

        const entries = items().map(button => ({ button, rect: button.getBoundingClientRect() }));
        const distance = ({ rect }) => Math.max(rect.top - y, y - rect.bottom, 0);
        const nearest = entries.reduce((best, entry) => distance(entry) < distance(best) ? entry : best);
        const row = entries.filter(entry => entry.button !== source && entry.rect.top === nearest.rect.top);
        const next = row.find(({ rect }) => x < rect.left + rect.width / 2);
        if (next) return { button: next.button, after: false };
        return row.length ? { button: row.at(-1).button, after: true } : null;
    };
    const clearDropMarker = target => target?.button.classList.remove('is-drop-before', 'is-drop-after');

    list.addEventListener('pointerdown', event => {
        const button = event.target.closest('[data-quick-action-item]');
        if (!button || drag || !event.isPrimary || event.button !== 0) return;
        drag = { button, id: event.pointerId, x: event.clientX, y: event.clientY };
        button.setPointerCapture(event.pointerId);
    });
    list.addEventListener('pointermove', event => {
        if (drag?.id !== event.pointerId) return;
        if (!drag.moved) {
            if (Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < 5) return;
            drag.moved = true;
            selectItem(null);
            drag.button.classList.add('is-dragging');
        }
        const target = dropAt(event.clientX, event.clientY, drag.button);
        if (drag.target?.button === target?.button && drag.target?.after === target?.after) return;
        clearDropMarker(drag.target);
        drag.target = target;
        target?.button.classList.add(target.after ? 'is-drop-after' : 'is-drop-before');
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => list.addEventListener(type, event => {
        if (drag?.id !== event.pointerId) return;
        const { button, target, moved } = drag;
        drag = null;
        clearDropMarker(target);
        button.classList.remove('is-dragging');
        if (!moved) return;
        suppressClick = true;
        window.setTimeout(() => { suppressClick = false; });
        if (type === 'pointerup' && target) move(button, target.button, target.after);
    }));

    const requestIds = new WeakMap();
    document.addEventListener('nexoms:order-status', event => {
        const allowsStatus = element => {
            const statuses = JSON.parse(element.dataset.actionStatuses);
            return statuses.length === 0 || statuses.includes(event.detail);
        };
        runButtons.forEach(button => { button.hidden = !allowsStatus(button); });
        noActions.hidden = runButtons.some(button => !button.hidden);
        original.forEach(element => {
            element.toggleAttribute('data-unavailable', !allowsStatus(element));
        });
        refreshRows();
    });

    view.addEventListener('click', async event => {
        const button = event.target.closest('[data-quick-action-run]');
        if (!button || running) return;
        const confirmed = button.hasAttribute('data-requires-confirmation');
        if (confirmed && !requestIds.has(button) && !window.confirm('Uruchomić akcję „' + button.dataset.actionLabel + '”?')) return;
        running = true;
        button.disabled = edit.disabled = true;
        button.setAttribute('aria-busy', 'true');
        status.textContent = 'Sprawdzanie reguł...';

        try {
            const requestId = requestIds.get(button) || crypto.randomUUID();
            requestIds.set(button, requestId);
            let result = await send(button.dataset.quickActionRun, 'POST', { request_id: requestId, confirmed });
            for (let attempt = 0; result.status === 'pending' && attempt < 18; attempt++) {
                await new Promise(resolve => window.setTimeout(resolve, attempt < 4 ? 1000 : 2000));
                result = await send(result.status_url);
            }
            if (result.status === 'pending') {
                notify('OMS nadal sprawdza reguły. Kliknij ponownie, aby sprawdzić wynik tego samego zlecenia.', 'waiting');
            } else {
                requestIds.delete(button);
                if (['failed', 'unavailable'].includes(result.status)) notify(result.message);
                else if (result.status === 'checked' && result.run_count === 0) notify(result.message, 'waiting');
                document.dispatchEvent(new Event('nexoms:automation-wake'));
            }
        } catch (failure) {
            if (failure.status >= 400 && failure.status < 500) requestIds.delete(button);
            notify(failure.message);
        } finally {
            running = false;
            button.removeAttribute('aria-busy');
            status.textContent = '';
            button.disabled = edit.disabled = false;
        }
    });
})();
