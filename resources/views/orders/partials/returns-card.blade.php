@if ($order->returns->isNotEmpty())
    @php
        $returnMoney = app(\Modules\Invoices\Services\InvoiceMoneyFormatter::class);
    @endphp
    <section class="nex-card order-returns-card" aria-labelledby="orderReturnsTitle">
        <div class="nex-card-header">
            <h2 class="nex-card-title" id="orderReturnsTitle"><i class="bi bi-arrow-return-left" aria-hidden="true"></i> Zwroty</h2>
        </div>
        <div class="nex-card-body">
            <div class="order-returns-table-wrap" tabindex="0" role="region" aria-label="Zwroty do zamówienia">
                <table class="table order-returns-table">
                    <colgroup>
                        <col style="width:21%"><col style="width:12.5%"><col style="width:11.5%">
                        <col style="width:14.5%"><col style="width:11%"><col style="width:15.5%"><col style="width:14%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Numer (nr ref.)</th>
                            <th scope="col">Źródło</th>
                            <th scope="col">Ilość pozycji</th>
                            <th scope="col">Łączna wartość</th>
                            <th scope="col">Status</th>
                            <th scope="col">Data utworzenia</th>
                            <th scope="col">Data w statusie</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->returns as $orderReturn)
                            @php
                                $sourceLabel = trim((string) $orderReturn->account_name) ?: match ($orderReturn->source) {
                                    'manual' => 'Ręczne', 'allegro' => 'Allegro', 'prestashop' => 'PrestaShop',
                                    'von_halsky' => 'InPost Von Halsky', default => $orderReturn->source ?: 'Inne',
                                };
                                $sourceIcon = match ($orderReturn->source) {
                                    'prestashop' => 'bi-cart3', 'von_halsky' => 'bi-shop', default => 'bi-pencil-square',
                                };
                            @endphp
                            <tr data-order-return-id="{{ $orderReturn->id }}">
                                <td>
                                    <a class="order-return-number" href="{{ route('returns.show', $orderReturn) }}">{{ $orderReturn->id }}</a>
                                    @if (filled($orderReturn->reference_number))<span class="order-return-reference">({{ $orderReturn->reference_number }})</span>@endif
                                </td>
                                <td>
                                    <span class="order-return-source">
                                        <span class="order-return-source-icon" aria-hidden="true">
                                            @if ($orderReturn->source === 'allegro')
                                                al
                                            @else
                                                <i class="bi {{ $sourceIcon }}"></i>
                                            @endif
                                        </span>
                                        <span>{{ $sourceLabel }}</span>
                                    </span>
                                </td>
                                <td>{{ $orderReturn->items_count }}</td>
                                <td class="order-return-amount">{{ $returnMoney->format($orderReturn->total_value) }} {{ $orderReturn->currency }}</td>
                                <td><span class="order-return-status" style="background: {{ $orderReturn->localStatus?->color ?? '#9ca3af' }}; color: {{ $orderReturn->localStatus?->textColor() ?? '#ffffff' }};">{{ $orderReturn->localStatusLabel() }}</span></td>
                                <td>{{ ($orderReturn->submitted_at ?? $orderReturn->created_at)?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '...' }}</td>
                                <td>{{ $orderReturn->local_status_changed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '...' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endif
