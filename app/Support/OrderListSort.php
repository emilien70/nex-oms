<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class OrderListSort
{
    private function __construct(public readonly string $key, public readonly string $direction) {}

    public static function options(): array
    {
        return array_map(fn (string $label): string => html_entity_decode($label, ENT_QUOTES, 'UTF-8'), [
            'id' => 'ID zam&oacute;wienia',
            'store_number' => 'Numer w sklepie',
            'ordered_at' => 'Data z&#322;o&#380;enia',
            'status_at' => 'Data w statusie',
            'total' => '&#321;&#261;czna kwota',
            'delivery_cost' => 'Koszt wysy&#322;ki',
            'shipping_method' => 'Spos&oacute;b wysy&#322;ki',
            'status' => 'Status',
            'payment' => 'Stan p&#322;atno&#347;ci',
            'customer' => 'Imi&#281; i nazwisko',
            'login' => 'Login kupuj&#261;cego',
            'allegro_transaction' => 'Numer transakcji Allegro',
            'tracking_number' => 'Numer nadawczy',
        ]);
    }

    public static function fromArray(array $values): self
    {
        $key = $values['sort'] ?? 'id';
        $direction = $values['direction'] ?? 'desc';

        return new self(
            is_string($key) && array_key_exists($key, self::options()) ? $key : 'id',
            in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc',
        );
    }

    public function parameters(): array
    {
        return $this->key === 'id' && $this->direction === 'desc'
            ? [] : ['sort' => $this->key, 'direction' => $this->direction];
    }

    public function apply(Builder $query, array $statuses): Builder
    {
        $query->reorder();
        if ($this->key !== 'id') {
            [$expression, $bindings] = $this->expression($statuses);
            $query->orderByRaw("CASE WHEN ($expression) IS NULL THEN 1 ELSE 0 END ASC", $bindings)
                ->orderByRaw("($expression) $this->direction", $bindings);
        }

        return $query->orderBy('orders.id', $this->direction);
    }

    /** @return array{previous: int|null, next: int|null} */
    public function adjacentIds(Order $order, Builder $query, array $statuses): array
    {
        if ($this->key === 'id') {
            $operator = $this->direction === 'asc' ? '>' : '<';

            return array_map(fn ($id): ?int => $id === null ? null : (int) $id, [
                'previous' => (clone $query)->reorder()->where('orders.id', $operator === '>' ? '<' : '>', $order->id)
                    ->orderBy('orders.id', $this->direction === 'asc' ? 'desc' : 'asc')->value('orders.id'),
                'next' => (clone $query)->reorder()->where('orders.id', $operator, $order->id)
                    ->orderBy('orders.id', $this->direction)->value('orders.id'),
            ]);
        }

        [$expression, $bindings] = $this->expression($statuses);
        $candidates = (clone $query)->reorder()->select('orders.id')
            ->selectRaw("($expression) AS sort_value", $bindings)->toBase();
        $anchor = Order::query()->whereKey($order->id)->select('orders.id')
            ->selectRaw("($expression) AS sort_value", $bindings)->toBase();
        $neighbors = DB::query()->fromSub($candidates, 'sort_candidates')->crossJoinSub($anchor, 'sort_anchor');

        // Compare values inside SQL, including DECIMALs, without a PHP float round-trip.
        return [
            'previous' => $this->neighbor((clone $neighbors), true),
            'next' => $this->neighbor((clone $neighbors), false),
        ];
    }

    private function neighbor(QueryBuilder $query, bool $previous): ?int
    {
        $direction = $previous ? ($this->direction === 'asc' ? 'desc' : 'asc') : $this->direction;
        $operator = $direction === 'asc' ? '>' : '<';
        $candidateMissing = 'CASE WHEN sort_candidates.sort_value IS NULL THEN 1 ELSE 0 END';
        $anchorMissing = 'CASE WHEN sort_anchor.sort_value IS NULL THEN 1 ELSE 0 END';
        $rankOperator = $previous ? '<' : '>';
        $id = $query->where(function (QueryBuilder $where) use ($operator, $candidateMissing, $anchorMissing, $rankOperator): void {
            $where->whereRaw("($candidateMissing) $rankOperator ($anchorMissing)")
                ->orWhere(function (QueryBuilder $sameRank) use ($operator, $candidateMissing, $anchorMissing): void {
                    $sameRank->whereRaw("($candidateMissing) = ($anchorMissing)")
                        ->where(function (QueryBuilder $values) use ($operator): void {
                            $values->whereColumn('sort_candidates.sort_value', $operator, 'sort_anchor.sort_value')
                                ->orWhere(function (QueryBuilder $tie) use ($operator): void {
                                    $tie->where(function (QueryBuilder $equal): void {
                                        $equal->whereColumn('sort_candidates.sort_value', 'sort_anchor.sort_value')
                                            ->orWhere(fn (QueryBuilder $nulls) => $nulls->whereNull('sort_candidates.sort_value')->whereNull('sort_anchor.sort_value'));
                                    })->whereColumn('sort_candidates.id', $operator, 'sort_anchor.id');
                                });
                        });
                });
        })->orderByRaw($candidateMissing.($previous ? ' DESC' : ' ASC'))
            ->orderBy('sort_candidates.sort_value', $direction)
            ->orderBy('sort_candidates.id', $direction)->value('sort_candidates.id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{string, array} */
    private function expression(array $statuses): array
    {
        if ($this->key === 'status') {
            $clauses = [];
            foreach (array_keys($statuses) as $position => $code) {
                $clauses[] = 'WHEN ? THEN '.$position;
            }

            return $clauses === [] ? ['NULL', []]
                : ['CASE orders.status '.implode(' ', $clauses).' ELSE NULL END', array_keys($statuses)];
        }

        if ($this->key === 'tracking_number') {
            $tracking = DB::table('shipments')->selectRaw("LOWER(NULLIF(TRIM(tracking_number), ''))")
                ->whereColumn('order_id', 'orders.id')->whereRaw("NULLIF(TRIM(tracking_number), '') IS NOT NULL")
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1);

            return ['('.$tracking->toSql().')', $tracking->getBindings()];
        }

        return [match ($this->key) {
            'store_number' => "CASE WHEN orders.source = 'prestashop' THEN CAST(NULLIF(TRIM(orders.external_id), '') AS DECIMAL(30, 0)) ELSE NULL END",
            'ordered_at' => 'COALESCE(orders.purchased_at, orders.created_at)',
            'status_at' => 'COALESCE(orders.status_changed_at, orders.created_at)',
            'total' => 'orders.total_gross',
            'delivery_cost' => 'orders.delivery_cost_gross',
            'shipping_method' => "LOWER(NULLIF(TRIM(orders.shipping_method), ''))",
            'payment' => 'CASE WHEN COALESCE(orders.paid_amount, 0) <= 0 THEN 0 WHEN orders.paid_amount < orders.total_gross THEN 1 ELSE 2 END',
            'customer' => "LOWER(COALESCE(NULLIF(TRIM(orders.shipping_name), ''), NULLIF(TRIM(orders.customer_login), '')))",
            'login' => "LOWER(NULLIF(TRIM(orders.customer_login), ''))",
            'allegro_transaction' => "CASE WHEN orders.source = 'allegro' THEN LOWER(NULLIF(TRIM(orders.external_id), '')) ELSE NULL END",
            default => 'orders.id',
        }, []];
    }
}
