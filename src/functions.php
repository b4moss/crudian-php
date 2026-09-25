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
