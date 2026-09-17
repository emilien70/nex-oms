<script>
document.addEventListener('DOMContentLoaded', () => {
    const taxpayer = document.querySelector('[name="jpk_type"]');
    const updateTaxpayer = () => document.querySelectorAll('[data-taxpayer]').forEach(row => {
        row.hidden = row.dataset.taxpayer !== taxpayer.value;
        row.querySelector('input').disabled = row.hidden;
    });
    taxpayer?.addEventListener('change', updateTaxpayer);
    if (taxpayer) updateTaxpayer();
    document.querySelectorAll('[data-tax-office]').forEach(container => {
        const search = container.querySelector('[data-office-search]');
        const select = container.querySelector('[data-office-select]');
        const options = [...select.options].map(option => option.cloneNode(true));
        let selected = select.value;
        const normalize = text => text.toLocaleLowerCase('pl').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ł/g, 'l');
        const render = () => {
            const query = normalize(search.value.trim());
            const matches = options.filter(option => option.value && normalize(option.textContent).includes(query));
            const visible = options.filter(option => !option.value || option.value === selected || matches.includes(option));
            select.replaceChildren(...visible.map(option => option.cloneNode(true)));
            select.value = selected;
            select.size = query ? Math.min(6, Math.max(2, visible.length)) : 1;
            container.querySelector('[data-office-results]').textContent = query ? `Wyniki: ${matches.length}` : '';
        };
        search.addEventListener('input', render);
        search.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); select.focus(); }
            if (event.key === 'Enter') { event.preventDefault(); select.focus(); }
            if (event.key === 'Escape') { search.value = ''; render(); }
        });
        select.addEventListener('change', () => { selected = select.value; search.value = ''; render(); });
        container.querySelector('[data-office-search-wrap]').hidden = false;
    });
});
</script>
