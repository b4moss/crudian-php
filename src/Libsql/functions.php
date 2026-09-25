<?php

declare(strict_types=1);

namespace B4moss\Crudian\Libsql;

use B4moss\Crudian\Crud;
use B4moss\Crudian\CrudianError;
use B4moss\Crudian\Executor;
use B4moss\Crudian\Options;
use B4moss\Crudian\SqliteDialect;

/**
 * Thin executor over turso/libsql Connection (technical preview).
 *
 * Expects an object with query/execute APIs compatible with Libsql\Connection:
 * - execute(string $sql, array $args = []): object with rowsAffected() / columns() / fetchArray()
 * or a PDO-compatible Libsql\PDO.
 */
final class LibsqlExecutor implements Executor
{
    public function __construct(
        private readonly object $conn,
        private readonly ?object $tx = null,
    ) {
    }

    private function client(): object
    {
        return $this->tx ?? $this->conn;
    }

    public function run(string $sql, array $args = []): int
    {
        $client = $this->client();
        if ($client instanceof \PDO) {
            $stmt = $client->prepare($sql);
            $stmt->execute($args);

            return $stmt->rowCount();
        }
        if (method_exists($client, 'execute')) {
            $result = $client->execute($sql, $args);
            if (is_object($result) && method_exists($result, 'rowsAffected')) {
                return (int) $result->rowsAffected();
            }
            if (is_object($result) && method_exists($result, 'changes')) {
                return (int) $result->changes();
            }

            return 0;
        }
        throw CrudianError::of('libsql client does not support execute');
    }

    public function get(string $sql, array $args = []): ?array
    {
        $rows = $this->all($sql, $args);

        return $rows[0] ?? null;
    }

    public function all(string $sql, array $args = []): array
    {
        $client = $this->client();
        if ($client instanceof \PDO) {
            $stmt = $client->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $out[] = $row;
            }

            return $out;
        }
        if (method_exists($client, 'query')) {
            $result = $client->query($sql, $args);
            return $this->rowsFromResult($result);
        }
        if (method_exists($client, 'execute')) {
            $result = $client->execute($sql, $args);

            return $this->rowsFromResult($result);
        }
        throw CrudianError::of('libsql client does not support query');
    }

    public function transaction(callable $fn): void
    {
        $client = $this->conn;
        if ($client instanceof \PDO) {
            $client->beginTransaction();
            try {
                $fn(new self($client, $client));
                $client->commit();
            } catch (\Throwable $e) {
                if ($client->inTransaction()) {
                    $client->rollBack();
                }
                throw $e;
            }

            return;
        }
        if (method_exists($client, 'transaction')) {
            $client->transaction(function (object $tx) use ($fn): void {
                $fn(new self($this->conn, $tx));
            });

            return;
        }
        // Fallback: run without real TX if SDK lacks it (preview).
        $fn($this);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromResult(mixed $result): array
    {
        if (!is_object($result)) {
            return [];
        }
        if (method_exists($result, 'fetchArray')) {
            $out = [];
            while ($row = $result->fetchArray()) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        }
        if (method_exists($result, 'fetchAll')) {
            /** @var list<array<string, mixed>> $all */
            $all = $result->fetchAll();

            return $all;
        }
        if (property_exists($result, 'rows') && is_array($result->rows)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $result->rows;

            return $rows;
        }

        return [];
    }
}

final class Facade
{
    public function __construct(
        private readonly Crud $inner,
        public readonly object $db,
    ) {
    }

    /** @param array<string, mixed> $cols */
    public function create(string $table, array $cols): array
    {
        return $this->inner->create($table, $cols);
    }

    /** @param array<string, mixed> $query */
    public function read(string $table, array $query = []): ?array
    {
        return $this->inner->read($table, $query);
    }

    /** @param array<string, mixed> $cols @param array<string, mixed> $query */
    public function update(string $table, array $cols, array $query = []): ?array
    {
        return $this->inner->update($table, $cols, $query);
    }

    /** @param array<string, mixed> $query */
    public function delete(string $table, array $query = []): int
    {
        return $this->inner->delete($table, $query);
    }

    /** @param array<string, mixed> $query */
    public function count(string $table, array $query = []): int
    {
        return $this->inner->count($table, $query);
    }

    /** @param array<string, mixed> $query */
    public function exists(string $table, array $query = []): bool
    {
        return $this->inner->exists($table, $query);
    }

    /** @param array<string, mixed> $query */
    public function search(string $table, array $query = []): \B4moss\Crudian\SearchResult
    {
        return $this->inner->search($table, $query);
    }

    /** @param array<string, mixed> $query */
    public function list(string $table, array $query = []): \B4moss\Crudian\SearchResult
    {
        return $this->inner->list($table, $query);
    }

    /** @param array<string, mixed> $cols */
    public function upsert(string $table, array $cols): array
    {
        return $this->inner->upsert($table, $cols);
    }

    /** @param array<string, mixed> $query */
    public function duplicate(string $table, array $query = []): ?array
    {
        return $this->inner->duplicate($table, $query);
    }

    /** @param list<array<string, mixed>> $rows */
    public function bulkCreate(string $table, array $rows): int
    {
        return $this->inner->bulkCreate($table, $rows);
    }

    /** @param array<string, mixed> $cols @param array<string, mixed> $query */
    public function bulkUpdate(string $table, array $cols, array $query = []): int
    {
        return $this->inner->bulkUpdate($table, $cols, $query);
    }

    /** @param array<string, mixed> $query */
    public function bulkDelete(string $table, array $query = []): int
    {
        return $this->inner->bulkDelete($table, $query);
    }

    /** @param list<array<string, mixed>> $rows */
    public function bulkUpsert(string $table, array $rows): int
    {
        return $this->inner->bulkUpsert($table, $rows);
    }

    /** @param callable(Facade): void $fn */
    public function transaction(callable $fn): void
    {
        $this->inner->transaction(function (Crud $tx) use ($fn): void {
            $fn(new Facade($tx, $this->db));
        });
    }
}

function createCrud(object $connection, ?Options $options = null): Facade
{
    $ex = new LibsqlExecutor($connection);
    $inner = Crud::newWith($ex, new SqliteDialect(), $options);

    return new Facade($inner, $connection);
}
