<script>
    (() => {
        const list = document.querySelector('[data-automation-sort-list]');
        const feedback = document.querySelector('[data-automation-order-feedback]');
        const rows = () => Array.from(list.querySelectorAll('[data-automation-sort-row]'));
        const ids = entries => entries.map(row => row.dataset.automationSortRow);
        const topbar = document.querySelector('.app-topbar');
        let saving = false;
        let drag = null;
        let frame = null;

        const saveMove = async (row, index) => {
            const original = rows();
            const oldIndex = original.indexOf(row);
            if (saving || index === oldIndex || index < 0 || index >= original.length) return;

            const editor = row.nextElementSibling;
            const handle = row.querySelector('[data-automation-sort-handle]');
            const hadFocus = document.activeElement === handle;
            const reordered = original.filter(item => item !== row);
            reordered.splice(index, 0, row);
            const place = before => {
                list.insertBefore(row, before ?? null);
                row.after(editor);
                if (hadFocus) handle.focus({ preventScroll: true });
            };

            saving = true;
            list.setAttribute('aria-busy', 'true');
            place(reordered[index + 1]);

            try {
                const response = await fetch(list.dataset.automationSortList, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ rule_ids: ids(reordered), previous_rule_ids: ids(original) }),
                });
                if (response.status === 409) {
                    throw new Error('Lista regu\u0142 zmieni\u0142a si\u0119. Od\u015bwie\u017c stron\u0119 i spr\u00f3buj ponownie.');
                }
                if (!response.ok || !(await response.json()).saved) throw new Error();
                feedback.textContent = `Pozycja ${index + 1} z ${reordered.length}.`;
            } catch (error) {
                place(original[oldIndex + 1]);
                window.nexOmsShowActivity?.({
                    tone: 'error',
                    title: 'Automatyczne akcje',
                    message: error.message || 'Nie uda\u0142o si\u0119 zapisa\u0107 kolejno\u015bci regu\u0142.',
                });
            } finally {
                saving = false;
                list.removeAttribute('aria-busy');
            }
        };

        const markDrop = () => {
            const index = drag.others.findIndex(row => {
                const bounds = row.getBoundingClientRect();
                return drag.y < (bounds.top + bounds.bottom) / 2;
            });
            const row = index < 0 ? drag.others.at(-1) : drag.others[index];
            const mark = index < 0 ? 'automation-sort-after' : 'automation-sort-before';
            if (drag.marker?.row !== row || drag.marker?.mark !== mark) {
                drag.marker?.row.classList.remove(drag.marker.mark);
                row.classList.add(mark);
                drag.marker = { row, mark };
            }
            drag.index = index < 0 ? drag.others.length : index;
        };

        const scrollAtEdge = () => {
            frame = null;
            if (!drag?.others) return;
            const top = topbar?.getBoundingClientRect().bottom ?? 0;
            const direction = drag.y < top + 36 ? -1 : (drag.y > window.innerHeight - 36 ? 1 : 0);
            if (!direction) return;
            window.scrollBy({ top: direction * 12, behavior: 'instant' });
            markDrop();
            frame = window.requestAnimationFrame(scrollAtEdge);
        };

        list.addEventListener('pointerdown', event => {
            const handle = event.target.closest('[data-automation-sort-handle]');
            if (!handle || saving || drag || !event.isPrimary || event.button !== 0) return;
            drag = { id: event.pointerId, row: handle.closest('[data-automation-sort-row]'), startY: event.clientY };
            handle.setPointerCapture(event.pointerId);
        });

        list.addEventListener('pointermove', event => {
            if (drag?.id !== event.pointerId) return;
            if (!drag.others) {
                if (Math.abs(event.clientY - drag.startY) < 5) return;
                drag.others = rows().filter(row => row !== drag.row);
                drag.row.classList.add('automation-sort-dragging');
            }
            drag.y = event.clientY;
            markDrop();
            if (frame === null) frame = window.requestAnimationFrame(scrollAtEdge);
        });

        const endDrag = event => {
            if (drag?.id !== event.pointerId) return;
            const { row, marker, index } = drag;
            drag = null;
            if (frame !== null) window.cancelAnimationFrame(frame);
            frame = null;
            row.classList.remove('automation-sort-dragging');
            marker?.row.classList.remove(marker.mark);
            if (event.type === 'pointerup' && index !== undefined) saveMove(row, index);
        };
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => list.addEventListener(type, endDrag));

        list.addEventListener('keydown', event => {
            const handle = event.target.closest('[data-automation-sort-handle]');
            const direction = { ArrowUp: -1, ArrowDown: 1 }[event.key];
            if (!handle || !direction || saving || drag || event.altKey || event.ctrlKey || event.metaKey) return;
            event.preventDefault();
            const row = handle.closest('[data-automation-sort-row]');
            saveMove(row, rows().indexOf(row) + direction);
        });
    })();
</script>
