<?php

namespace Tests\Unit;

use App\Support\OrderListFilterState;
use App\Support\OrderListSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderListSortTest extends TestCase
{
    public function test_default_is_id_descending_without_extra_url_parameters(): void
    {
        $sort = OrderListSort::fromArray([]);
        $this->assertSame('id', $sort->key);
        $this->assertSame('desc', $sort->direction);
        $this->assertSame([], $sort->parameters());
        $this->assertCount(13, OrderListSort::options());
    }

    #[DataProvider('invalidSorts')]
    public function test_invalid_input_cannot_become_a_column_or_sql_direction(array $values): void
    {
        $sort = OrderListSort::fromArray($values);
        $this->assertSame([], $sort->parameters());
    }

    public static function invalidSorts(): array
    {
        return [[['sort' => 'total; DROP TABLE orders', 'direction' => 'desc; SELECT 1']],
            [['sort' => ['total'], 'direction' => ['asc']]], [['sort' => 'unknown']],
            [['direction' => 'ASC']], [['sort' => null, 'direction' => null]]];
    }

    public function test_saved_views_keep_non_default_sorting_and_normalize_default_sorting(): void
    {
        $this->assertSame(['source' => 'allegro', 'sort' => 'total', 'direction' => 'asc'],
            OrderListFilterState::normalizeSaved(['source' => 'allegro', 'sort' => 'total', 'direction' => 'asc']));
        $this->assertSame(['source' => 'allegro'],
            OrderListFilterState::normalizeSaved(['source' => 'allegro', 'sort' => 'id', 'direction' => 'desc']));
        $this->assertSame(['sort' => 'id', 'direction' => 'asc'], OrderListSort::fromArray(['direction' => 'asc'])->parameters());
    }
}
