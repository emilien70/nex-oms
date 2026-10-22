<div class="nex-card order-email-card" data-order-email-exchange>
    <div class="nex-card-header">
        <h2 class="nex-card-title">Wymiana wiadomości</h2>
    </div>
    <div class="nex-card-body order-email-card-body">
        @if (filled($buyerComment = $order->allegroOrderLink?->buyer_message))
            <article class="order-buyer-comment" aria-label="Komentarz do zamówienia">
                <div class="order-buyer-comment-header">
                    <span>{{ $order->allegroOrderLink->buyer_name ?: ($order->shipping_name ?: $order->customer_login) }}:</span>
                    <time title="Data zakupu">{{ $order->purchased_at?->format('d.m.Y H:i') }}</time>
                    <span class="order-buyer-comment-label"><i class="bi bi-chat" aria-hidden="true"></i> Komentarz do zamówienia.</span>
                </div>
                <div class="order-buyer-comment-body">{{ $buyerComment }}</div>
            </article>
        @endif
        <div class="alert alert-danger py-2 px-3 mb-3 d-none" role="alert" data-email-form-error></div>

        <form class="order-email-compose d-none" method="POST" action="{{ route('orders.email-messages.store', $order) }}" data-order-email-form>
            @csrf
            <input type="hidden" name="message_type" value="email">
            <input type="hidden" name="format" value="html" data-email-format>
            <input type="hidden" name="content_rendered" value="0" data-email-content-rendered>
            <input type="hidden" name="idempotency_key" value="" data-email-idempotency-key>
            <textarea name="body" class="d-none" data-email-body-input></textarea>

            <div class="order-email-form-row">
                <label class="form-label mb-0" for="orderEmailTemplate">Szablon:</label>
                <select id="orderEmailTemplate" name="email_template_id" class="form-select form-select-sm" data-email-template>
                    <option value="">Nie wybrano</option>
                    @foreach ($emailTemplates as $emailTemplate)
                        <option value="{{ $emailTemplate->getKey() }}" data-preview-url="{{ route('orders.email-templates.preview', [$order, $emailTemplate]) }}">{{ $emailTemplate->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="order-email-form-row order-email-type-row">
                <span class="form-label mb-0">Typ wiadomości:</span>
                <div class="order-email-type-options">
                    <label class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="radio" checked>
                        <span class="form-check-label">E-mail</span>
                    </label>
                    <label class="form-check form-check-inline mb-0" title="Obsługa SMS zostanie dodana w przyszłości">
                        <input class="form-check-input" type="radio" disabled>
                        <span class="form-check-label text-secondary">SMS</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="visually-hidden" for="orderEmailSubject">Temat wiadomości</label>
                <input id="orderEmailSubject" name="subject" class="form-control form-control-sm" type="text" maxlength="255" placeholder="Temat wiadomości" required data-email-subject>
            </div>

            <div class="order-email-editor-shell" data-email-editor-shell>
                <div class="order-email-toolbar" role="toolbar" aria-label="Formatowanie wiadomości" data-email-toolbar>
                    <button type="button" class="email-editor-button" data-email-source title="Kod HTML" aria-label="Kod HTML"><i class="bi bi-code-slash"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="bold" title="Pogrubienie" aria-label="Pogrubienie"><i class="bi bi-type-bold"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="italic" title="Kursywa" aria-label="Kursywa"><i class="bi bi-type-italic"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="underline" title="Podkreślenie" aria-label="Podkreślenie"><i class="bi bi-type-underline"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="removeFormat" title="Wyczyść formatowanie" aria-label="Wyczyść formatowanie"><i class="bi bi-eraser"></i></button>
                    <select class="email-editor-select" data-email-font aria-label="Krój pisma" title="Krój pisma">
                        <option value="Arial">Arial</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Georgia">Georgia</option>
                    </select>
                    <select class="email-editor-select email-editor-size" data-email-size aria-label="Rozmiar tekstu" title="Rozmiar tekstu">
                        <option value="2">10</option><option value="3" selected>11</option><option value="4">14</option><option value="5">18</option>
                    </select>
                    <label class="email-editor-color" title="Kolor tekstu"><i class="bi bi-fonts" aria-hidden="true"></i><input type="color" value="#111827" data-email-color aria-label="Kolor tekstu"></label>
                    <button type="button" class="email-editor-button" data-email-command="insertUnorderedList" title="Lista punktowana" aria-label="Lista punktowana"><i class="bi bi-list-ul"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="insertOrderedList" title="Lista numerowana" aria-label="Lista numerowana"><i class="bi bi-list-ol"></i></button>
                    <button type="button" class="email-editor-button" data-email-command="justifyLeft" title="Wyrównaj do lewej" aria-label="Wyrównaj do lewej"><i class="bi bi-text-left"></i></button>
                    <button type="button" class="email-editor-button" data-email-link title="Dodaj odnośnik" aria-label="Dodaj odnośnik"><i class="bi bi-link-45deg"></i></button>
                    <button type="button" class="email-editor-button" data-email-table title="Dodaj tabelę" aria-label="Dodaj tabelę"><i class="bi bi-table"></i></button>
                    <button type="button" class="email-editor-button" data-email-image title="Dodaj obraz z adresu HTTPS" aria-label="Dodaj obraz z adresu HTTPS"><i class="bi bi-image"></i></button>
                </div>
                <div class="order-email-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Treść wiadomości" data-email-editor></div>
                <textarea class="order-email-source d-none" aria-label="Kod HTML wiadomości" data-email-source-editor></textarea>
            </div>

            <div class="order-email-attachment-preview d-none" data-email-attachments></div>

            <div class="order-email-form-row">
                <label class="form-label mb-0" for="orderEmailAccount">Konto e-mail:</label>
                <select id="orderEmailAccount" name="email_account_id" class="form-select form-select-sm" required data-email-account>
                    @forelse ($emailAccounts as $emailAccount)
                        <option value="{{ $emailAccount->getKey() }}" @selected($emailAccount->getKey() === $defaultEmailAccountId)>
                            {{ $emailAccount->email }} - {{ $emailAccount->name }} ({{ $emailAccount->smtp_host }}:{{ $emailAccount->smtp_port }})
                        </option>
                    @empty
                        <option value="">Brak skonfigurowanego konta e-mail</option>
                    @endforelse
                </select>
            </div>

            <div class="order-email-form-row order-email-send-row">
                <label class="form-label mb-0" for="orderEmailSendingOption">Opcje wysyłania:</label>
                <div class="input-group input-group-sm">
                    <select id="orderEmailSendingOption" name="sending_option" class="form-select" required>
                        <option value="page_and_email">Wiadomość na stronie zamówienia + e-mail (z treścią)</option>
                    </select>
                    <button class="btn btn-primary" type="submit" @disabled($emailAccounts->isEmpty()) data-email-submit>Wyślij</button>
                </div>
            </div>

            @if ($emailAccounts->isEmpty())
                <div class="small text-danger">Najpierw skonfiguruj konto SMTP w zakładce Konta E-Mail.</div>
            @endif
        </form>

        <div class="order-email-empty {{ $emailMessages->isNotEmpty() ? 'd-none' : '' }}" data-email-empty>Brak wiadomości wysłanych do klienta.</div>

        <div class="order-email-messages" data-email-messages>
            @foreach ($emailMessages as $message)
                @include('components.order-email-message-item', ['order' => $order, 'message' => $message])
            @endforeach
        </div>

        <button class="btn btn-primary btn-sm order-email-compose-toggle" type="button" data-email-compose-toggle>
            <i class="bi bi-envelope me-1" aria-hidden="true"></i>
            <span data-email-compose-toggle-label>Napisz wiadomość</span>
            <i class="bi bi-chevron-down ms-1" aria-hidden="true"></i>
        </button>
    </div>
</div>
