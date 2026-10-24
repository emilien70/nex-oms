<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class LiteralLike
{
    public static function contains(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    public static function where(Builder $query, string $column, string $pattern, string $boolean = 'and'): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw($column." LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }
}
