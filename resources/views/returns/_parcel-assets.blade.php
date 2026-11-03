@once
<style>
    .return-parcel-status{display:flex;align-items:center;gap:8px;color:inherit;text-decoration:none;font-size:12px;min-width:220px}
    .return-parcel-track{display:block;width:100px;min-width:100px;height:8px;border-radius:4px;background:#e5e7eb;overflow:hidden}
    .return-parcel-fill{display:block;height:100%;background:#49a1da}.return-parcel-fill.is-delivered{background:#167743}.return-parcel-fill.is-problem{background:#d8483e}
    .return-parcel-reference{display:block;font-size:11px}.return-parcel-form{display:flex;align-items:end;gap:8px;flex-wrap:wrap}.return-parcel-form label{font-size:12px;min-width:140px}.return-parcel-number{flex:1;max-width:400px}
    .return-shipment-status{display:flex;align-items:center;gap:8px;min-width:220px;font-size:12px}.return-shipment-status .return-parcel-fill.bg-success{background:#167743}
</style>
<div class="modal fade" id="returnParcelHistoryModal" tabindex="-1" aria-labelledby="returnParcelHistoryTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-6" id="returnParcelHistoryTitle">Historia przesyłki zwrotnej</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button></div><div class="modal-body" data-return-parcel-history-body></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const element = document.getElementById('returnParcelHistoryModal');
    const body = element.querySelector('[data-return-parcel-history-body]');
    let request;
    const load = async (url) => {
        request?.abort(); request = new AbortController();
        body.textContent = 'Ładowanie…';
        bootstrap.Modal.getOrCreateInstance(element).show();
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: request.signal});
            if (!response.ok) throw new Error();
            const data = await response.json(); body.innerHTML = data.html;
        } catch (error) { if(error.name !== 'AbortError') body.textContent = 'Nie udało się pobrać historii. Spróbuj ponownie.'; }
    };
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-return-parcel-history], #returnParcelHistoryModal .pagination a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault(); load(link.href);
    });
});
</script>
@endonce
