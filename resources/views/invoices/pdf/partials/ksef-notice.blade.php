@if (data_get($document, 'ksef.test_mark'))
    <div class="ksef-test-mark">{{ $document['ksef']['test_mark'] }}</div>
    <br>
@endif

@if (data_get($document, 'ksef.preview_warning'))
    <div class="ksef-preview-warning">{{ $document['ksef']['preview_warning'] }}</div>
    <br>
@endif
