<?php

declare(strict_types=1);

namespace B4moss\Crudian\Pdo;

use B4moss\Crudian\Crud;
use B4moss\Crudian\CrudianError;
use B4moss\Crudian\Executor;
use B4moss\Crudian\Options;
use B4moss\Crudian\SqliteDialect;
use PDO;
use PDOException;
use PDOStatement;

final class PdoExecutor implements Executor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly bool $inTx = false,
    ) {
    }

    public function run(string $sql, array $args = []): int
    {
        $stmt = $this->prepare($sql, $args);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function get(string $sql, array $args = []): ?array
    {
        $stmt = $this->prepare($sql, $args);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    public function all(string $sql, array $args = []): array
    {
        $stmt = $this->prepare($sql, $args);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    public function transaction(callable $fn): void
    {
        if ($this->inTx) {
            $fn($this);

            return;
        }
        $this->pdo->beginTransaction();
        try {
            $fn(new self($this->pdo, true));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function prepare(string $sql, array $args): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw CrudianError::of('failed to prepare statement');
            }
            foreach ($args as $i => $value) {
                $stmt->bindValue($i + 1, $value);
            }

            return $stmt;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}

final class Facade
{
    public function __construct(
        private readonly Crud $inner,
        public readonly PDO $db,
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

function createCrud(PDO $pdo, ?Options $options = null): Facade
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ex = new PdoExecutor($pdo);
    $dialect = null;
    if ($options !== null && $options->dialect !== null) {
        $dialect = $options->dialect;
    } elseif ($options !== null && $options->driver !== null && $options->driver !== '') {
        $dialect = \B4moss\Crudian\resolveDialect($options->driver);
    } else {
        $dialect = inferDialectFromPdo($pdo);
    }
    $inner = Crud::newWith($ex, $dialect, $options);

    return new Facade($inner, $pdo);
}

function inferDialectFromPdo(PDO $pdo): \B4moss\Crudian\Dialect
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!is_string($driver)) {
        return new SqliteDialect();
    }

    return match (strtolower($driver)) {
        'pgsql' => new \B4moss\Crudian\PostgresDialect(),
        'mysql' => new \B4moss\Crudian\MySQLDialect(),
        default => new SqliteDialect(),
    };
}
