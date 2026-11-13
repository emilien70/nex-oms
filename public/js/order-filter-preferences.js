(() => {
    const keys = JSON.parse(document.currentScript.dataset.orderFilterKeys);
    const storagePrefix = 'nexOmsOrderFilters:v1:';
    const baseLinks = new WeakMap();

    const read = (scope) => {
        try {
            return JSON.parse(window.localStorage.getItem(storagePrefix + scope)) ?? {};
        } catch {
            return {};
        }
    };

    const addFilters = (url, scope) => {
        const values = read(scope);
        for (const key of keys) {
            if (typeof values[key] === 'string' && values[key].trim() !== '') {
                url.searchParams.set(key, values[key]);
            }
        }
        return url;
    };

    const refreshLinks = () => {
        document.querySelectorAll('a[data-order-filter-target]').forEach((link) => {
            if (!baseLinks.has(link)) {
                baseLinks.set(link, link.href);
            }

            link.href = addFilters(new URL(baseLinks.get(link)), link.dataset.orderFilterTarget).href;
        });
    };

    const remember = (scope, params) => {
        const values = {};
        for (const key of keys) {
            const value = params.get(key)?.trim();
            if (value) values[key] = value;
        }

        try {
            if (Object.keys(values).length) {
                window.localStorage.setItem(storagePrefix + scope, JSON.stringify(values));
            } else {
                window.localStorage.removeItem(storagePrefix + scope);
            }
        } catch {
            return;
        }
        refreshLinks();
    };

    const restoreEntry = () => {
        const form = document.querySelector('[data-order-filter-scope]');
        const url = new URL(window.location.href);
        // Explicit URLs, including saved views, pagination and order-card returns, win.
        if (!form || [...url.searchParams.keys()].some((key) => key !== 'status' && key !== 'trash')) {
            return false;
        }

        const previous = url.href;
        addFilters(url, form.dataset.orderFilterScope);
        if (url.href === previous) return false;

        window.location.replace(url.href);
        return true;
    };

    window.nexOmsOrderFilters = { refreshLinks, remember, restoreEntry };
    refreshLinks();
    window.addEventListener('pageshow', refreshLinks);
    window.addEventListener('storage', (event) => {
        if (event.key === null || event.key.startsWith(storagePrefix)) refreshLinks();
    });
})();
