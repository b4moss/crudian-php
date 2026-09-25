<?php

declare(strict_types=1);

namespace B4moss\Crudian\Tests;

use B4moss\Crudian\WhereBuilder;
use PHPUnit\Framework\TestCase;
use function B4moss\Crudian\Libsql\createCrud as createLibsqlCrud;

/**
 * Exercises the thin libSQL Executor against a PDO stand-in (SDK preview boundary).
 * Real turso/libsql + FFI is validated in CI when available.
 */
final class LibsqlExecutorTest extends TestCase
{
    public function testContractViaPdoCompatibleClient(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            score INTEGER NOT NULL
        )');

        $crud = createLibsqlCrud($pdo);
        $this->assertSame($pdo, $crud->db);

        $row = $crud->create('items', ['name' => 'lib', 'score' => 1]);
        $this->assertSame('lib', $row['name']);
        $this->assertTrue($crud->exists('items', ['where' => WhereBuilder::create()->eq('name', 'lib')]));
        $page = $crud->search('items', ['limit' => 10]);
        $this->assertSame(1, $page->total);

        $crud->transaction(function ($tx) {
            $tx->create('items', ['name' => 'in-tx', 'score' => 2]);
        });
        $this->assertSame(2, $crud->count('items'));
    }
}
