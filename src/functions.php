<?php

declare(strict_types=1);

namespace B4moss\Crudian;

/**
 * @param mixed $value
 */
function assertNonEmptyString(mixed $value, string $label): string
{
    if (!is_string($value) || $value === '') {
        throw CrudianError::of("{$label} must be a non-empty string");
    }

    return $value;
}

function resolveDialect(string $name): Dialect
{
    $key = strtolower(trim($name));
    return match ($key) {
        '', 'sqlite' => new SqliteDialect(),
        'postgres', 'postgresql' => new PostgresDialect(),
        'mysql' => new MySQLDialect(),
        default => throw CrudianError::of('unknown dialect: ' . $name),
    };
}

function resolveOptionsDialect(?Options $options): Dialect
{
    if ($options === null) {
        return new SqliteDialect();
    }
    if ($options->dialect !== null) {
        return $options->dialect;
    }
    if ($options->driver !== null && $options->driver !== '') {
        return resolveDialect($options->driver);
    }

    return new SqliteDialect();
}

function resolvePk(?Options $options): string
{
    if ($options === null || $options->pk === null || $options->pk === '') {
        return 'id';
    }

    return $options->pk;
}

function where(): WhereBuilder
{
    return WhereBuilder::create();
}

/**
 * @return list<mixed>
 */
function asList(mixed $value): array
{
    if (!is_array($value)) {
        throw CrudianError::of('in value must be an array');
    }
    if ($value === []) {
        throw CrudianError::of('in requires a non-empty array');
    }
    if (array_is_list($value)) {
        return $value;
    }

    return array_values($value);
}

function joinComma(array $parts): string
{
    return implode(', ', $parts);
}

/**
 * @param list<string> $columns
 */
function selectColumns(Dialect $d, array $columns): string
{
    if ($columns === []) {
        return '*';
    }
    $parts = [];
    foreach ($columns as $c) {
        $parts[] = $d->quoteIdent(assertNonEmptyString($c, 'column'));
    }

    return joinComma($parts);
}

function compileWhere(Dialect $d, ?WhereNode $node, int $startIndex = 1): CompiledWhere
{
    if ($startIndex < 1) {
        $startIndex = 1;
    }
    if ($node === null) {
        return new CompiledWhere('', [], $startIndex);
    }

    if ($node instanceof GroupNode) {
        if ($node->children === []) {
            return new CompiledWhere('', [], $startIndex);
        }
        $parts = [];
        $args = [];
        $idx = $startIndex;
        foreach ($node->children as $child) {
            $c = compileWhere($d, $child, $idx);
            if ($c->sql === '') {
                continue;
            }
            $parts[] = '(' . $c->sql . ')';
            $args = [...$args, ...$c->args];
            $idx = $c->nextIndex;
        }
        if ($parts === []) {
            return new CompiledWhere('', [], $startIndex);
        }
        if (count($parts) === 1) {
            $sql = $parts[0];

            return new CompiledWhere(substr($sql, 1, -1), $args, $idx);
        }
        $join = $node->type === 'or' ? ' OR ' : ' AND ';

        return new CompiledWhere(implode($join, $parts), $args, $idx);
    }

    if ($node instanceof CondNode) {
        $col = assertNonEmptyString($node->column, 'column');
        $q = $d->quoteIdent($col);
        $idx = $startIndex;
        return match ($node->op) {
            'eq' => new CompiledWhere($q . ' = ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'ne' => new CompiledWhere($q . ' <> ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'lt' => new CompiledWhere($q . ' < ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'gt' => new CompiledWhere($q . ' > ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'lte' => new CompiledWhere($q . ' <= ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'gte' => new CompiledWhere($q . ' >= ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'like' => new CompiledWhere($q . ' LIKE ' . $d->placeholder($idx), [$node->value], $idx + 1),
            'isNull' => new CompiledWhere($q . ' IS NULL', [], $idx),
            'isNotNull' => new CompiledWhere($q . ' IS NOT NULL', [], $idx),
            'in' => (static function () use ($d, $q, $idx, $node): CompiledWhere {
                $vals = asList($node->value);
                $ph = [];
                $i = $idx;
                foreach ($vals as $_) {
                    $ph[] = $d->placeholder($i);
                    $i++;
                }

                return new CompiledWhere($q . ' IN (' . joinComma($ph) . ')', $vals, $i);
            })(),
            default => throw CrudianError::of('unknown op: ' . $node->op),
        };
    }

    throw CrudianError::of('invalid where node');
}

function resolveWhere(?WhereBuilder $w): ?WhereNode
{
    return $w?->toNode();
}

/**
 * @param array<string, mixed> $cols
 * @return list<string>
 */
function sortedKeys(array $cols): array
{
    $keys = array_keys($cols);
    sort($keys, SORT_STRING);

    return $keys;
}

function toInt(mixed $v): int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return (int) $v;
    }
    if (is_string($v) && is_numeric($v)) {
        return (int) $v;
    }
    if (is_bool($v)) {
        return $v ? 1 : 0;
    }

    return 0;
}

/**
 * @param array<string, mixed> $query
 */
function validateSearchExtras(array $query, string $paging): void
{
    $orderBy = $query['orderBy'] ?? null;
    if ($orderBy !== null) {
        if (!is_array($orderBy)) {
            throw CrudianError::of('orderBy must be an array');
        }
        foreach ($orderBy as $clause) {
            if (!is_array($clause)) {
                throw CrudianError::of('orderBy clause must be an object');
            }
            if (!isset($clause['column']) || !is_string($clause['column'])) {
                throw CrudianError::of('orderBy.column must be a string');
            }
            $dir = $clause['direction'] ?? null;
            if ($dir !== null && $dir !== 'asc' && $dir !== 'desc') {
                throw CrudianError::of('orderBy.direction must be "asc" or "desc"');
            }
        }
    }

    $groupBy = $query['groupBy'] ?? null;
    if ($groupBy !== null) {
        if (!is_array($groupBy)) {
            throw CrudianError::of('groupBy must be an array');
        }
        if ($groupBy === []) {
            throw CrudianError::of('groupBy must not be empty');
        }
        foreach ($groupBy as $col) {
            if (!is_string($col)) {
                throw CrudianError::of('groupBy column must be a string');
            }
        }
    }

    $aggregates = $query['aggregates'] ?? null;
    if ($aggregates !== null) {
        if (!is_array($aggregates)) {
            throw CrudianError::of('aggregates must be an array');
        }
        if (!is_array($groupBy) || count($groupBy) < 1) {
            throw CrudianError::of('aggregates require groupBy');
        }
        $ok = ['count' => true, 'sum' => true, 'avg' => true, 'min' => true, 'max' => true];
        foreach ($aggregates as $agg) {
            if (!is_array($agg)) {
                throw CrudianError::of('aggregate must be an object');
            }
            $fn = $agg['fn'] ?? null;
            if (!is_string($fn) || !isset($ok[$fn])) {
                throw CrudianError::of('aggregate.fn is invalid');
            }
            $as = $agg['as'] ?? null;
            if (!is_string($as) || $as === '') {
                throw CrudianError::of('aggregate.as must be a non-empty string');
            }
            if ($fn !== 'count') {
                $col = $agg['column'] ?? null;
                if (!is_string($col) || $col === '') {
                    throw CrudianError::of('aggregate.column is required');
                }
            } elseif (array_key_exists('column', $agg) && !is_string($agg['column'])) {
                throw CrudianError::of('aggregate.column must be a string');
            }
        }
    }

    $orderByNonEmpty = is_array($orderBy) && count($orderBy) > 0;
    $groupByNonEmpty = is_array($groupBy) && count($groupBy) > 0;
    if ($paging === 'cursor' && $orderByNonEmpty) {
        throw CrudianError::of('cursor paging does not accept orderBy');
    }
    if ($paging === 'cursor' && $groupByNonEmpty) {
        throw CrudianError::of('cursor paging does not accept groupBy');
    }
}

/**
 * @param array<string, mixed> $agg
 */
function aggregateSql(Dialect $d, array $agg): string
{
    $alias = $d->quoteIdent((string) $agg['as']);
    $fn = strtoupper((string) $agg['fn']);
    $col = $agg['column'] ?? null;
    if (($agg['fn'] ?? '') === 'count' && ($col === null || $col === '')) {
        return $fn . '(*) AS ' . $alias;
    }

    return $fn . '(' . $d->quoteIdent((string) $col) . ') AS ' . $alias;
}

/**
 * @param array<string, mixed> $query
 * @return array{selectSql: string, groupBySql: string, orderBySql: string, hasGroupBy: bool}
 */
function buildSearchSqlExtras(Dialect $d, array $query, string $pk): array
{
    $groupBy = $query['groupBy'] ?? null;
    $hasGroupBy = is_array($groupBy) && $groupBy !== [];
    /** @var list<array<string, mixed>> $aggregates */
    $aggregates = is_array($query['aggregates'] ?? null) ? $query['aggregates'] : [];

    if ($hasGroupBy) {
        /** @var list<string> $cols */
        $cols = (isset($query['columns']) && is_array($query['columns']) && $query['columns'] !== [])
            ? $query['columns']
            : $groupBy;
        $parts = [];
        foreach ($cols as $c) {
            $parts[] = $d->quoteIdent((string) $c);
        }
        foreach ($aggregates as $agg) {
            $parts[] = aggregateSql($d, $agg);
        }
        $selectSql = joinComma($parts);
        $gb = [];
        foreach ($groupBy as $c) {
            $gb[] = $d->quoteIdent((string) $c);
        }
        $groupBySql = ' GROUP BY ' . joinComma($gb);
    } else {
        /** @var list<string> $columns */
        $columns = is_array($query['columns'] ?? null) ? $query['columns'] : [];
        $selectSql = selectColumns($d, $columns);
        $groupBySql = '';
    }

    $orderBy = $query['orderBy'] ?? null;
    if (is_array($orderBy) && $orderBy !== []) {
        $parts = [];
        foreach ($orderBy as $clause) {
            /** @var array<string, mixed> $clause */
            $dir = strtoupper((string) ($clause['direction'] ?? 'asc'));
            $parts[] = $d->quoteIdent((string) $clause['column']) . ' ' . $dir;
        }
        $orderBySql = ' ORDER BY ' . joinComma($parts);
    } elseif ($hasGroupBy) {
        $orderBySql = ' ORDER BY ' . $d->quoteIdent((string) $groupBy[0]) . ' ASC';
    } else {
        $orderBySql = ' ORDER BY ' . $d->quoteIdent($pk) . ' ASC';
    }

    return [
        'selectSql' => $selectSql,
        'groupBySql' => $groupBySql,
        'orderBySql' => $orderBySql,
        'hasGroupBy' => $hasGroupBy,
    ];
}
