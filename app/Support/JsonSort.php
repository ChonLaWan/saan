<?php

namespace App\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Numeric ORDER BY on a JSON key. MySQL/MariaDB json_unquote(json_extract()) returns text, so a plain
 * orderBy('metadata->sort_order') sorts 10 before 2.
 */
class JsonSort
{
    public static function numeric(QueryBuilder|EloquentBuilder $query, string $key = 'sort_order', string $column = 'metadata', string $direction = 'asc')
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) throw new \InvalidArgumentException('json sort key');
        $expr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`$column`, '$.$key')) AS SIGNED)";
        return $query->orderByRaw($expr.(strtolower($direction) === 'desc' ? ' DESC' : ' ASC'));
    }
}
