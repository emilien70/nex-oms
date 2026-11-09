<script>
    document.addEventListener('DOMContentLoaded', () => {
        const slotSelector = '[data-list-toolbar-slot]';
        const container = document.querySelector(slotSelector)?.closest('.orders-page')?.parentElement;
        if (!container) return;

        const topbar = document.querySelector('.app-topbar');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const visible = { opacity: 1, transform: 'none' };
        const hidden = { opacity: 0, transform: 'translateY(100%)' };
        let state = null;
        let frame = null;

        const setDocked = (current, docked, animate) => {
            const { dock, spacer } = current;
            const style = current.animation ? window.getComputedStyle(dock) : null;
            const from = style ? { opacity: style.opacity, transform: style.transform } : (docked ? hidden : visible);
            current.animation?.cancel();
            current.animation = null;
            current.docked = docked;

            dock.inert = !docked;
            if (docked) {
                dock.hidden = false;
            } else {
                dock.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]').forEach((toggle) => {
                    window.bootstrap?.Dropdown.getInstance(toggle)?.hide();
                });
            }

            const finish = () => {
                if (!docked) {
                    dock.hidden = true;
                    spacer.style.removeProperty('height');
                }
            };

            if (!animate || reducedMotion.matches || typeof dock.animate !== 'function') {
                finish();
                return;
            }

            const animation = dock.animate([from, docked ? visible : hidden], {
                duration: 250,
                easing: 'ease-in-out',
                fill: 'both',
            });
            current.animation = animation;
            animation.onfinish = () => {
                if (state !== current || current.animation !== animation) return;
                current.animation = null;
                finish();
                animation.cancel();
            };
        };

        const update = (animate = true) => {
            if (!state) return;
            const { slot, dock, table, spacer } = state;
            const slotRect = slot.getBoundingClientRect();
            const tableRect = table.getBoundingClientRect();
            const viewport = document.documentElement;
            const top = topbar?.getBoundingClientRect().bottom ?? 0;
            const left = Math.max(0, tableRect.left);
            const width = Math.min(viewport.clientWidth, tableRect.right) - left;
            const docked = width > 0 && slotRect.bottom <= top && tableRect.bottom > top
                && tableRect.top < viewport.clientHeight;

            if (docked || !dock.hidden) {
                dock.style.setProperty('--list-toolbar-left', `${left}px`);
                dock.style.setProperty('--list-toolbar-width', `${Math.max(0, width)}px`);
            }
            if (state.docked !== docked) setDocked(state, docked, animate);
            if (!dock.hidden) {
                const height = `${dock.offsetHeight}px`;
                spacer.style.height = height;
                dock.style.setProperty('--list-toolbar-height', height);
            }
        };

        const scheduleUpdate = () => {
            if (frame !== null) return;
            frame = window.requestAnimationFrame(() => {
                frame = null;
                update();
            });
        };

        const sizes = new ResizeObserver(scheduleUpdate);
        const mount = () => {
            const slot = container.querySelector(slotSelector);
            if (slot === state?.slot) return;
            state?.animation?.cancel();
            sizes.disconnect();
            state = null;

            const page = slot?.closest('.orders-page');
            const dock = page?.querySelector('[data-list-toolbar-dock]');
            const table = page?.querySelector('.orders-table-card');
            const spacer = page?.querySelector('[data-list-toolbar-spacer]');
            if (!slot || !dock || !table || !spacer) return;

            dock.querySelectorAll('.dropdown').forEach((menu) => menu.classList.add('dropup'));
            state = { slot, dock, table, spacer, docked: false, animation: null };
            [slot, dock, table, topbar].filter(Boolean).forEach((element) => sizes.observe(element));
            update(false);
        };

        mount();
        new MutationObserver(mount).observe(container, { childList: true });
        window.addEventListener('scroll', scheduleUpdate, { passive: true });
        window.addEventListener('resize', scheduleUpdate);

        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('[data-list-toolbar-dock] .nex-pagination-toolbar a[href]');
            if (!link || !state?.docked || !state.slot.id || !state.dock.contains(link)) return;
            const url = new URL(link.href);
            url.hash = state.slot.id;
            event.preventDefault();
            window.location.assign(url.toString());
        });
    });
</script>
