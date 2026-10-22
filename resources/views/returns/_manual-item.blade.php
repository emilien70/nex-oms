<tr>
    <td><input class="form-control product-id" type="number" min="1" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] ?? '' }}" aria-label="ID produktu"></td>
    <td><input class="form-control" name="items[{{ $index }}][name]" value="{{ $item['name'] ?? '' }}" maxlength="10000" required aria-label="Nazwa produktu"></td>
    <td><input class="form-control quantity" type="number" min="1" max="1000000" step="1" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 1 }}" required aria-label="Ilość"></td>
    <td><input class="form-control price" type="number" min="0" step="0.01" name="items[{{ $index }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}" required aria-label="Cena produktu"></td>
    <td><input class="form-control" name="items[{{ $index }}][reason]" value="{{ $item['reason'] ?? '' }}" maxlength="100" aria-label="Powód zwrotu"></td>
    <td><input class="form-control" name="items[{{ $index }}][comment]" value="{{ $item['comment'] ?? '' }}" maxlength="20000" aria-label="Komentarz do produktu"></td>
    <td><button class="btn btn-sm btn-outline-secondary" type="button" title="Usuń pozycję" aria-label="Usuń pozycję" data-remove-return-item><i class="bi bi-trash3" aria-hidden="true"></i></button></td>
</tr>
