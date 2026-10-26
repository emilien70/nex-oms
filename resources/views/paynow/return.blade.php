<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <link rel="icon" href="data:,">
    <link rel="icon" href="data:,">
    <title>Status płatności - NEX-OMS</title>
    <style nonce="{{ $nonce }}">
        * { box-sizing: border-box; }
        body { margin: 0; color: #243447; background: #f5f7f9; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 20px; min-height: 72px; padding: 16px 24px; border-bottom: 1px solid #e0e5eb; background: #fff; }
        .brand { color: #263746; font-weight: 750; font-size: 20px; }
        .brand span { color: #0075c9; }
        .provider { color: #687585; font-size: 14px; }
        main { width: min(100% - 40px, 520px); margin: 80px auto 40px; }
        .status-mark { display: grid; place-items: center; width: 52px; height: 52px; border-radius: 50%; background: #e4eff8; color: #0070ae; font-size: 28px; font-weight: 700; margin-bottom: 24px; }
        [data-state="confirmed"] .status-mark, [data-state="settled"] .status-mark { color: #157245; background: #def2e6; }
        [data-state="failed"] .status-mark { color: #b32f36; background: #fbe6e7; }
        [data-state="unavailable"] .status-mark { color: #687585; background: #e7ebef; }
        h1 { font-size: 26px; line-height: 1.3; font-weight: 650; margin: 0 0 12px; overflow-wrap: anywhere; }
        p { margin: 0 0 24px; color: #596879; }
        dl { padding: 18px 0; margin: 28px 0; border-block: 1px solid #dce3ea; }
        dl div { display: grid; grid-template-columns: 135px minmax(0, 1fr); gap: 12px; margin: 8px 0; }
        dt { color: #687585; }
        dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
        button { min-height: 44px; border-radius: 6px; padding: 10px 18px; border: 1px solid #0075c9; background: #0075c9; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #0063ab; }
        button:focus-visible { outline: 3px solid #80bce9; outline-offset: 3px; }
        button:disabled { opacity: .6; cursor: wait; }
        .secondary { color: #43586d; background: transparent; border-color: #bcc8d4; }
        .secondary:hover { background: #eaf0f5; }
        form { margin: 0 0 12px; }
        .error { padding: 12px 14px; border-left: 3px solid #b32f36; background: #fbe6e7; color: #7f2027; margin-bottom: 24px; }
        .test { display: inline-block; color: #895400; background: #fff0cf; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-bottom: 18px; }
        [hidden] { display: none !important; }
        @media (max-width: 575px) {
            header { min-height: 64px; padding: 14px 20px; }
            main { margin-top: 44px; }
            h1 { font-size: 24px; }
            button { width: 100%; }
            dl div { grid-template-columns: 1fr; gap: 2px; margin: 14px 0; }
        }
    </style>
</head>
<body>
    <header><div class="brand">NEX<span>-OMS</span></div><div class="provider">Płatności PayNow</div></header>
    <main data-state="{{ $data['state'] }}">
        @if ($data['isTest'])<div class="test">Tryb testowy</div>@endif
        <div class="status-mark" aria-hidden="true" id="status-mark">{{ in_array($data['state'], ['confirmed', 'settled'], true) ? '✓' : ($data['state'] === 'failed' ? '!' : '…') }}</div>
        <section aria-live="polite" aria-atomic="true">
            <h1 id="payment-title">{{ $data['title'] }}</h1>
            <p id="payment-message">{{ $data['message'] }}</p>
        </section>
        @if (session('paynow_customer_error'))<div class="error" role="alert">{{ session('paynow_customer_error') }}</div>@endif
        @if ($data['amount'] !== null)
            <dl>
                <div><dt>Kwota płatności</dt><dd>{{ str_replace('.', ',', $data['amount']) }} {{ $data['currency'] }}</dd></div>
                <div id="transaction-row" @if (!$data['transactionId']) hidden @endif><dt>ID transakcji</dt><dd id="transaction-id">{{ $data['transactionId'] }}</dd></div>
            </dl>
        @endif
        @if ($retryUrl)
            <form method="POST" action="{{ $retryUrl }}" id="retry-form" @if (!$data['canRetry']) hidden @endif>
                @csrf
                <button type="submit" id="retry-button">Ponów płatność</button>
            </form>
        @endif
        @if ($statusUrl)
            <form method="POST" action="{{ $statusUrl }}" id="status-form" @if (in_array($data['state'], ['confirmed', 'settled'], true)) hidden @endif>
                @csrf
                <button type="submit" class="secondary" id="status-button">Sprawdź status</button>
            </form>
        @endif
    </main>
    @if ($statusUrl)
        <script nonce="{{ $nonce }}">
            (() => {
                const form = document.getElementById('status-form');
                const retryForm = document.getElementById('retry-form');
                const button = document.getElementById('status-button');
                let busy = false;
                let timer;
                let checks = 0;
                const update = async (event) => {
                    event?.preventDefault();
                    if (busy) return;
                    clearTimeout(timer);
                    busy = true;
                    button.disabled = true;
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST', credentials: 'same-origin',
                            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                        });
                        if (!response.ok) throw new Error('status_unavailable');
                        const data = await response.json();
                        document.querySelector('main').dataset.state = data.state;
                        document.getElementById('payment-title').textContent = data.title;
                        document.getElementById('payment-message').textContent = data.message;
                        document.getElementById('status-mark').textContent = ['confirmed', 'settled'].includes(data.state) ? '✓' : (data.state === 'failed' ? '!' : '…');
                        document.getElementById('transaction-row').hidden = !data.transactionId;
                        document.getElementById('transaction-id').textContent = data.transactionId || '';
                        retryForm.hidden = !data.canRetry;
                        form.hidden = ['confirmed', 'settled'].includes(data.state);
                        if (data.poll && ++checks < 6) timer = setTimeout(update, 6000);
                    } catch (error) {
                        retryForm.hidden = true;
                        document.getElementById('payment-message').textContent = 'Nie możemy teraz potwierdzić płatności. Spróbuj sprawdzić status za chwilę.';
                    } finally {
                        busy = false;
                        button.disabled = false;
                    }
                };
                form.addEventListener('submit', update);
                retryForm.addEventListener('submit', () => {
                    clearTimeout(timer);
                    document.getElementById('retry-button').disabled = true;
                });
                if (!form.hidden) update();
            })();
        </script>
    @endif
</body>
</html>
