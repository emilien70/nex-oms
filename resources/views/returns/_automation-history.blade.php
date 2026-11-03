@forelse($automationHistory as $run)
    <div class="py-2 border-bottom"><div class="d-flex flex-wrap justify-content-between gap-1 small"><span>{{ $run['actor'] }}, {{ $run['occurred_at'] }}</span><strong>{{ $run['trigger'] }}</strong></div>
    <table class="table table-sm small mb-1"><tbody>@foreach($run['steps'] as $step)<tr><td class="{{ $step['status'] === 'completed' ? 'text-success' : 'text-secondary' }}" title="{{ $step['status_label'] }}"><i class="bi bi-{{ $step['status'] === 'completed' ? 'check-lg' : 'dash' }}"></i></td><td>{{ $step['action'] }}</td><td>{{ $step['target'] }}@if($step['error'])<div class="text-danger">{{ $step['error'] }}</div>@endif</td></tr>@endforeach</tbody></table>
    @if($run['error'])<div class="small text-danger">{{ $run['error'] }}</div>@endif</div>
@empty<p class="small text-secondary py-2">Brak historii automatycznych akcji zwrotu.</p>@endforelse
{{ $automationHistory->links() }}
