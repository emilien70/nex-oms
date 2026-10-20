@php
    $hasValue = filled($value);
@endphp
@if ($field ?? null)
    <button class="inline-edit-value" type="button" data-edit-field="{{ $field }}" aria-label="Edytuj: {{ $label }} ({{ $hasValue ? $value : 'brak wartości' }})">
        <span class="inline-pencil" aria-hidden="true"><i class="bi bi-pencil"></i></span><span class="inline-edit-text {{ $hasValue ? '' : 'nex-empty' }}">{{ $hasValue ? $value : '...' }}</span>
    </button>
@else
    <span class="{{ $hasValue ? '' : 'nex-empty' }}">{{ $hasValue ? $value : '...' }}</span>
@endif
