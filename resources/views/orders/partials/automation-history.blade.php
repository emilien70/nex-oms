<div class="order-automation-history" data-automation-history-current-page="{{ $automationHistory->currentPage() }}">
    <div class="order-automation-history-heading">Wykonane akcje</div>
    @forelse ($automationHistory as $run)
        <div class="order-automation-run" data-automation-run-id="{{ $run['id'] }}">
            <div class="order-automation-run-heading">
                <span>{{ $run['actor'] }}, {{ $run['occurred_at'] ?? '...' }}</span>
                <strong title="{{ $run['rule_name'] }}">{{ $run['trigger'] }}</strong>
            </div>
            <table class="order-automation-steps" aria-label="{{ $run['rule_name'] }}">
                <colgroup><col class="order-automation-status-column"><col class="order-automation-action-column"><col></colgroup>
                <tbody>
                    @forelse ($run['steps'] as $step)
                        @php
                            $icon = match ($step['status']) {
                                'completed' => 'check-lg',
                                'failed' => 'x-lg',
                                'running' => 'arrow-repeat',
                                'waiting' => 'clock',
                                'skipped' => 'dash',
                                default => 'hourglass-split',
                            };
                        @endphp
                        <tr>
                            <td class="order-automation-step-status is-{{ $step['status'] }}" title="{{ $step['status_label'] }}" aria-label="{{ $step['status_label'] }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></td>
                            <td class="order-automation-step-action">{{ $step['action'] }}</td>
                            <td class="order-automation-step-target">
                                {{ $step['target'] }}
                                @if ($step['error'])<div class="order-automation-step-error">{{ $step['error'] }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="order-automation-empty">Brak zapisanych krok&oacute;w.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($run['error'])<div class="order-automation-step-error">{{ $run['error'] }}</div>@endif
        </div>
    @empty
        <div class="order-automation-empty">Brak historii automatycznych akcji.</div>
    @endforelse
    @if ($automationHistory->hasPages())
        <nav class="order-automation-pagination" aria-label="Strony historii automatycznych akcji">
            <button type="button" data-automation-history-page="{{ max(1, $automationHistory->currentPage() - 1) }}" @disabled($automationHistory->onFirstPage()) title="Nowsze akcje" aria-label="Nowsze akcje"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <span>{{ $automationHistory->currentPage() }} / {{ $automationHistory->lastPage() }}</span>
            <button type="button" data-automation-history-page="{{ $automationHistory->currentPage() + 1 }}" @disabled(! $automationHistory->hasMorePages()) title="Starsze akcje" aria-label="Starsze akcje"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </nav>
    @endif
</div>
