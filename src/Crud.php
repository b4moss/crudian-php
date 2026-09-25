<?php

declare(strict_types=1);

namespace B4moss\Crudian;

final class Crud
{
    /** @var array<string, true> */
    private array $pkOK = [];

    public function __construct(
        private readonly Executor $ex,
        private readonly Dialect $d,
        private readonly string $pk,
    ) {
    }

    public static function newWith(Executor $ex, ?Dialect $d = null, ?Options $options = null): self
    {
        $dialect = $d ?? resolveOptionsDialect($options);

        return new self($ex, $dialect, resolvePk($options));
    }

    private function ensurePkColumn(string $table): void
    {
        if (isset($this->pkOK[$table])) {
            return;
        }
        [$sql, $args] = $this->d->describeColumns($table);
        $rows = $this->ex->all($sql, $args);
        $found = false;
        foreach ($rows as $row) {
            $name = $row['name'] ?? $row['NAME'] ?? null;
            if ($name === $this->pk) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw CrudianError::of('pk column "' . $this->pk . '" does not exist on table "' . $table . '"');
        }
        $this->pkOK[$table] = true;
    }

    private function requireWhere(?WhereBuilder $w, string $label): void
    {
        if ($w === null) {
            throw CrudianError::of($label . ' requires where');
        }
    }

    /**
     * @param array<string, mixed> $cols
     * @return array<string, mixed>
     */
    public function create(string $table, array $cols): array
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        if ($cols === []) {
            throw CrudianError::of('cols must not be empty');
        }
        $keys = sortedKeys($cols);
        $qTbl = $this->d->quoteIdent($tbl);
        $colSQL = [];
        $ph = [];
        $args = [];
        $i = 1;
        foreach ($keys as $k) {
            $colSQL[] = $this->d->quoteIdent($k);
            $ph[] = $this->d->placeholder($i);
            $args[] = $cols[$k];
            $i++;
        }
        if ($this->d->supportsInsertReturning()) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
                $qTbl,
                joinComma($colSQL),
                joinComma($ph),
            );
            $row = $this->ex->get($sql, $args);
            if ($row === null) {
                throw CrudianError::of('expected row object');
            }

            return $row;
        }
        $this->ex->run(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $qTbl, joinComma($colSQL), joinComma($ph)),
            $args,
        );
        if (array_key_exists($this->pk, $cols)) {
            $pkValue = $cols[$this->pk];
        } else {
            $idRow = $this->ex->get($this->d->lastInsertIdSql());
            if ($idRow === null) {
                throw CrudianError::of('expected row object');
            }
            $pkValue = $idRow['id'] ?? null;
        }
        $row = $this->ex->get(
            'SELECT * FROM ' . $qTbl . ' WHERE ' . $this->d->quoteIdent($this->pk) . ' = ' . $this->d->placeholder(1),
            [$pkValue],
        );
        if ($row === null) {
            throw CrudianError::of('expected row object');
        }

        return $row;
    }

    /**
     * @param array{where?: ?WhereBuilder, columns?: list<string>} $query
     * @return array<string, mixed>|null
     */
    public function read(string $table, array $query = []): ?array
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);
        $sql = 'SELECT ' . selectColumns($this->d, $query['columns'] ?? []) . ' FROM ' . $this->d->quoteIdent($tbl);
        if ($where->sql !== '') {
            $sql .= ' WHERE ' . $where->sql;
        }
        $sql .= ' LIMIT 1';

        return $this->ex->get($sql, $where->args);
    }

    /**
     * @param array<string, mixed> $cols
     * @param array{where?: ?WhereBuilder} $query
     * @return array<string, mixed>|null
     */
    public function update(string $table, array $cols, array $query = []): ?array
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $this->requireWhere($query['where'] ?? null, 'update');
        if ($cols === []) {
            throw CrudianError::of('cols must not be empty');
        }
        $keys = sortedKeys($cols);
        $sets = [];
        $args = [];
        $i = 1;
        foreach ($keys as $k) {
            $sets[] = $this->d->quoteIdent($k) . ' = ' . $this->d->placeholder($i);
            $args[] = $cols[$k];
            $i++;
        }
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), count($keys) + 1);
        if ($where->sql === '') {
            throw CrudianError::of('update requires where');
        }
        $args = [...$args, ...$where->args];
        $qTbl = $this->d->quoteIdent($tbl);
        $n = $this->ex->run(
            sprintf('UPDATE %s SET %s WHERE %s', $qTbl, joinComma($sets), $where->sql),
            $args,
        );
        if ($n === 0) {
            return null;
        }
        $fetchWhere = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);

        return $this->ex->get(
            'SELECT * FROM ' . $qTbl . ' WHERE ' . $fetchWhere->sql . ' LIMIT 1',
            $fetchWhere->args,
        );
    }

    /**
     * @param array{where?: ?WhereBuilder} $query
     */
    public function delete(string $table, array $query = []): int
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $this->requireWhere($query['where'] ?? null, 'delete');
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);
        if ($where->sql === '') {
            throw CrudianError::of('delete requires where');
        }

        return $this->ex->run(
            'DELETE FROM ' . $this->d->quoteIdent($tbl) . ' WHERE ' . $where->sql,
            $where->args,
        );
    }

    /**
     * @param array{where?: ?WhereBuilder} $query
     */
    public function count(string $table, array $query = []): int
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);
        $sql = 'SELECT COUNT(*) AS ' . $this->d->quoteIdent('row_count') . ' FROM ' . $this->d->quoteIdent($tbl);
        if ($where->sql !== '') {
            $sql .= ' WHERE ' . $where->sql;
        }
        $row = $this->ex->get($sql, $where->args);
        if ($row === null) {
            return 0;
        }

        return toInt($row['row_count'] ?? $row['ROW_COUNT'] ?? 0);
    }

    /**
     * @param array{where?: ?WhereBuilder} $query
     */
    public function exists(string $table, array $query = []): bool
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);
        $sql = 'SELECT 1 AS ' . $this->d->quoteIdent('ok') . ' FROM ' . $this->d->quoteIdent($tbl);
        if ($where->sql !== '') {
            $sql .= ' WHERE ' . $where->sql;
        }
        $sql .= ' LIMIT 1';

        return $this->ex->get($sql, $where->args) !== null;
    }

    /**
     * @param array{
     *   where?: ?WhereBuilder,
     *   columns?: list<string>,
     *   limit?: int,
     *   cursor?: mixed,
     *   paging?: string,
     *   offset?: int|null
     * } $query
     */
    public function search(string $table, array $query = []): SearchResult
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $limit = $query['limit'] ?? 0;
        if ($limit === 0) {
            $limit = 20;
        }
        if ($limit <= 0) {
            throw CrudianError::of('limit must be a positive number');
        }

        $paging = $query['paging'] ?? '';
        if ($paging === '') {
            $paging = 'offset';
        }
        if ($paging !== 'offset' && $paging !== 'cursor') {
            throw CrudianError::of('paging must be "offset" or "cursor"');
        }
        $cursor = $query['cursor'] ?? null;
        $offsetOpt = array_key_exists('offset', $query) ? $query['offset'] : null;
        if ($paging === 'offset' && $cursor !== null) {
            throw CrudianError::of('offset paging does not accept cursor');
        }
        if ($paging === 'cursor' && $offsetOpt !== null) {
            throw CrudianError::of('cursor paging does not accept offset');
        }
        if ($cursor !== null && !is_int($cursor) && !is_float($cursor) && !is_string($cursor)) {
            throw CrudianError::of('cursor must be a number, string, or null');
        }

        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), 1);
        $total = $this->count($tbl, ['where' => $query['where'] ?? null]);

        if ($paging === 'offset') {
            $offset = is_int($offsetOpt) ? $offsetOpt : 0;
            if ($offset < 0) {
                throw CrudianError::of('offset must be a non-negative number');
            }
            $args = $where->args;
            $whereSQL = $where->sql !== '' ? ' WHERE ' . $where->sql : '';
            $idx = $where->nextIndex;
            $sql = 'SELECT ' . selectColumns($this->d, $query['columns'] ?? []) . ' FROM ' . $this->d->quoteIdent($tbl)
                . $whereSQL . ' ORDER BY ' . $this->d->quoteIdent($this->pk) . ' ASC LIMIT ' . $this->d->placeholder($idx)
                . ' OFFSET ' . $this->d->placeholder($idx + 1);
            $args[] = $limit;
            $args[] = $offset;
            $rows = $this->ex->all($sql, $args);

            return new SearchResult(
                items: $rows,
                hasMore: ($offset + count($rows)) < $total,
                total: $total,
                offset: $offset,
                limit: $limit,
            );
        }

        $args = $where->args;
        $parts = [];
        if ($where->sql !== '') {
            $parts[] = '(' . $where->sql . ')';
        }
        $idx = $where->nextIndex;
        if ($cursor !== null) {
            $parts[] = $this->d->quoteIdent($this->pk) . ' > ' . $this->d->placeholder($idx);
            $args[] = $cursor;
            $idx++;
        }
        $whereSQL = '';
        if ($parts !== []) {
            $whereSQL = ' WHERE ' . implode(' AND ', $parts);
        }
        $sql = 'SELECT ' . selectColumns($this->d, $query['columns'] ?? []) . ' FROM ' . $this->d->quoteIdent($tbl)
            . $whereSQL . ' ORDER BY ' . $this->d->quoteIdent($this->pk) . ' ASC LIMIT ' . $this->d->placeholder($idx);
        $args[] = $limit + 1;
        $rows = $this->ex->all($sql, $args);
        $hasMore = count($rows) > $limit;
        $items = $hasMore ? array_slice($rows, 0, $limit) : $rows;
        $next = null;
        if ($hasMore) {
            $last = $items[array_key_last($items)];
            $next = $last[$this->pk] ?? null;
        }

        return new SearchResult(
            items: $items,
            hasMore: $hasMore,
            total: $total,
            nextCursor: $next,
        );
    }

    /**
     * @param array{
     *   where?: ?WhereBuilder,
     *   columns?: list<string>,
     *   limit?: int,
     *   cursor?: mixed,
     *   paging?: string,
     *   offset?: int|null
     * } $query
     */
    public function list(string $table, array $query = []): SearchResult
    {
        return $this->search($table, $query);
    }

    /**
     * @param array<string, mixed> $cols
     * @return array<string, mixed>
     */
    public function upsert(string $table, array $cols): array
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        if ($cols === []) {
            throw CrudianError::of('cols must not be empty');
        }
        if (!array_key_exists($this->pk, $cols)) {
            throw CrudianError::of('upsert requires cols.' . $this->pk);
        }
        $id = $cols[$this->pk];
        $existing = $this->read($tbl, ['where' => WhereBuilder::create()->eq($this->pk, $id)]);
        if ($existing !== null) {
            $patch = [];
            foreach ($cols as $k => $v) {
                if ($k === $this->pk) {
                    continue;
                }
                $patch[$k] = $v;
            }
            if ($patch === []) {
                return $existing;
            }
            $updated = $this->update($tbl, $patch, ['where' => WhereBuilder::create()->eq($this->pk, $id)]);
            if ($updated === null) {
                throw CrudianError::of('upsert update failed');
            }

            return $updated;
        }

        return $this->create($tbl, $cols);
    }

    /**
     * @param array{where?: ?WhereBuilder, overrides?: array<string, mixed>} $query
     * @return array<string, mixed>|null
     */
    public function duplicate(string $table, array $query = []): ?array
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $this->requireWhere($query['where'] ?? null, 'duplicate');
        $source = $this->read($tbl, ['where' => $query['where'] ?? null]);
        if ($source === null) {
            return null;
        }
        $cols = [];
        foreach ($source as $k => $v) {
            if ($k === $this->pk) {
                continue;
            }
            $cols[$k] = $v;
        }
        foreach ($query['overrides'] ?? [] as $k => $v) {
            $cols[$k] = $v;
        }
        unset($cols[$this->pk]);

        return $this->create($tbl, $cols);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function bulkCreate(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            $this->create($table, $row);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $cols
     * @param array{where?: ?WhereBuilder} $query
     */
    public function bulkUpdate(string $table, array $cols, array $query = []): int
    {
        $tbl = assertNonEmptyString($table, 'table');
        $this->ensurePkColumn($tbl);
        $this->requireWhere($query['where'] ?? null, 'bulkUpdate');
        if ($cols === []) {
            throw CrudianError::of('cols must not be empty');
        }
        $keys = sortedKeys($cols);
        $sets = [];
        $args = [];
        $i = 1;
        foreach ($keys as $k) {
            $sets[] = $this->d->quoteIdent($k) . ' = ' . $this->d->placeholder($i);
            $args[] = $cols[$k];
            $i++;
        }
        $where = compileWhere($this->d, resolveWhere($query['where'] ?? null), count($keys) + 1);
        if ($where->sql === '') {
            throw CrudianError::of('bulkUpdate requires where');
        }
        $args = [...$args, ...$where->args];

        return $this->ex->run(
            sprintf('UPDATE %s SET %s WHERE %s', $this->d->quoteIdent($tbl), joinComma($sets), $where->sql),
            $args,
        );
    }

    /**
     * @param array{where?: ?WhereBuilder} $query
     */
    public function bulkDelete(string $table, array $query = []): int
    {
        return $this->delete($table, $query);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function bulkUpsert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            if (!array_key_exists($this->pk, $row)) {
                throw CrudianError::of('bulkUpsert requires each row to have ' . $this->pk);
            }
            $this->upsert($table, $row);
            $n++;
        }

        return $n;
    }

    /**
     * @param callable(self): void $fn
     */
    public function transaction(callable $fn): void
    {
        $this->ex->transaction(function (Executor $txEx) use ($fn): void {
            $tx = new self($txEx, $this->d, $this->pk);
            $tx->pkOK = $this->pkOK;
            $fn($tx);
        });
    }
}
