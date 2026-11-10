@foreach ($catalog->triggers() as $trigger => $label)
    <option value="{{ $trigger }}" @selected($selectedTrigger === $trigger)>{{ $label }}</option>
@endforeach
@if ($catalog->customTriggers())
    <optgroup label="Zdarzenia własne">
        @foreach ($catalog->customTriggers() as $trigger => $label)
            <option value="{{ $trigger }}" @selected($selectedTrigger === $trigger)>{{ $label }}</option>
        @endforeach
    </optgroup>
@endif
