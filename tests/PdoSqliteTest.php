<?php

declare(strict_types=1);

namespace B4moss\Crudian\Tests;

use B4moss\Crudian\MySQLDialect;
use B4moss\Crudian\Options;
use B4moss\Crudian\PostgresDialect;
use B4moss\Crudian\SqliteDialect;
use B4moss\Crudian\WhereBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use function B4moss\Crudian\Pdo\createCrud;
use function B4moss\Crudian\resolveDialect;

final class PdoSqliteTest extends TestCase
{
    private function open(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            score INTEGER NOT NULL,
            note TEXT
        )');

        return $pdo;
    }

    public function testCreateCrud(): void
    {
        $pdo = $this->open();
        $crud = createCrud($pdo);
        $this->assertSame($pdo, $crud->db);
    }

    public function testCoreCrud(): void
    {
        $crud = createCrud($this->open());
        $created = $crud->create('items', ['name' => 'alice', 'score' => 10]);
        $this->assertSame('alice', $created['name']);
        $this->assertNotEmpty($created['id']);
        $id = $created['id'];

        $got = $crud->read('items', ['where' => WhereBuilder::create()->eq('id', $id)]);
        $this->assertNotNull($got);
        $this->assertSame('alice', $got['name']);

        $proj = $crud->read('items', [
            'columns' => ['name'],
            'where' => WhereBuilder::create()->eq('id', $id),
        ]);
        $this->assertNotNull($proj);
        $this->assertSame('alice', $proj['name']);
        $this->assertArrayNotHasKey('score', $proj);

        $miss = $crud->read('items', ['where' => WhereBuilder::create()->eq('id', 99999)]);
        $this->assertNull($miss);

        $updated = $crud->update('items', ['score' => 11], ['where' => WhereBuilder::create()->eq('id', $id)]);
        $this->assertNotNull($updated);
        $this->assertSame(11, (int) $updated['score']);
        $this->assertSame('alice', $updated['name']);

        $none = $crud->update('items', ['score' => 1], ['where' => WhereBuilder::create()->eq('id', 99999)]);
        $this->assertNull($none);

        $n = $crud->delete('items', ['where' => WhereBuilder::create()->eq('id', $id)]);
        $this->assertSame(1, $n);
        $z = $crud->delete('items', ['where' => WhereBuilder::create()->eq('id', $id)]);
        $this->assertSame(0, $z);

        $this->expectException(\B4moss\Crudian\CrudianError::class);
        $crud->create('', ['name' => 'x', 'score' => 1]);
    }

    public function testSearchListCountExists(): void
    {
        $crud = createCrud($this->open());
        foreach (['a', 'b', 'c'] as $name) {
            $crud->create('items', ['name' => $name, 'score' => 1]);
        }

        $page = $crud->search('items', ['limit' => 2]);
        $this->assertCount(2, $page->items);
        $this->assertTrue($page->hasMore);
        $this->assertNull($page->nextCursor);
        $this->assertSame(3, $page->total);
        $this->assertSame(0, $page->offset);
        $this->assertSame(2, $page->limit);

        $page2 = $crud->search('items', ['limit' => 2, 'offset' => 2]);
        $this->assertCount(1, $page2->items);
        $this->assertFalse($page2->hasMore);
        $this->assertSame(2, $page2->offset);

        $cpage = $crud->search('items', ['paging' => 'cursor', 'limit' => 2]);
        $this->assertCount(2, $cpage->items);
        $this->assertTrue($cpage->hasMore);
        $this->assertNotNull($cpage->nextCursor);

        $cpage2 = $crud->search('items', [
            'paging' => 'cursor',
            'limit' => 2,
            'cursor' => $cpage->nextCursor,
        ]);
        $this->assertCount(1, $cpage2->items);
        $this->assertFalse($cpage2->hasMore);

        $list = $crud->list('items', ['limit' => 2]);
        $this->assertSame($page->total, $list->total);
        $this->assertCount(count($page->items), $list->items);

        $found = $crud->search('items', [
            'where' => WhereBuilder::create()->eq('name', 'b'),
            'limit' => 10,
        ]);
        $this->assertSame(1, $found->total);

        $or = $crud->search('items', [
            'where' => WhereBuilder::create()->eq('name', 'a')->or(WhereBuilder::create()->eq('name', 'c')),
            'limit' => 10,
        ]);
        $this->assertSame(2, $or->total);

        $in = $crud->search('items', [
            'where' => WhereBuilder::create()->in('name', ['a', 'b']),
            'limit' => 10,
        ]);
        $this->assertSame(2, $in->total);

        $this->assertSame(3, $crud->count('items'));
        $this->assertTrue($crud->exists('items'));
        $this->assertTrue($crud->exists('items', ['where' => WhereBuilder::create()->eq('name', 'a')]));
        $this->assertFalse($crud->exists('items', ['where' => WhereBuilder::create()->eq('name', 'zzz')]));
        $this->assertSame(
            $crud->exists('items', ['where' => WhereBuilder::create()->eq('name', 'a')]),
            $crud->count('items', ['where' => WhereBuilder::create()->eq('name', 'a')]) > 0,
        );

        $this->expectException(\B4moss\Crudian\CrudianError::class);
        $crud->search('items', ['paging' => 'offset', 'cursor' => 1]);
    }

    public function testExtendedWritesAndTransaction(): void
    {
        $crud = createCrud($this->open());
        $row = $crud->upsert('items', ['id' => 1, 'name' => 'x', 'score' => 1]);
        $this->assertSame(1, (int) $row['id']);
        $row2 = $crud->upsert('items', ['id' => 1, 'name' => 'y', 'score' => 2]);
        $this->assertSame('y', $row2['name']);

        $dup = $crud->duplicate('items', [
            'where' => WhereBuilder::create()->eq('id', 1),
            'overrides' => ['name' => 'z'],
        ]);
        $this->assertNotNull($dup);
        $this->assertNotSame(1, (int) $dup['id']);
        $this->assertSame('z', $dup['name']);

        $n = $crud->bulkCreate('items', [
            ['name' => 'b1', 'score' => 1],
            ['name' => 'b2', 'score' => 2],
        ]);
        $this->assertSame(2, $n);
        $this->assertSame(0, $crud->bulkCreate('items', []));

        $u = $crud->bulkUpdate('items', ['score' => 9], ['where' => WhereBuilder::create()->eq('name', 'b1')]);
        $this->assertSame(1, $u);

        $d = $crud->bulkDelete('items', ['where' => WhereBuilder::create()->eq('name', 'b2')]);
        $this->assertSame(1, $d);

        $bu = $crud->bulkUpsert('items', [
            ['id' => 1, 'name' => 'y2', 'score' => 3],
            ['id' => 100, 'name' => 'new', 'score' => 4],
        ]);
        $this->assertSame(2, $bu);

        $pdo = $this->open();
        $crud2 = createCrud($pdo);
        try {
            $crud2->transaction(function ($tx) {
                $tx->create('items', ['name' => 't1', 'score' => 1]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $crud2->count('items'));

        $crud2->transaction(function ($tx) {
            $tx->create('items', ['name' => 't2', 'score' => 1]);
            $tx->create('items', ['name' => 't3', 'score' => 1]);
        });
        $this->assertSame(2, $crud2->count('items'));
    }

    public function testPkOption(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE things (
            item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');
        $crud = createCrud($pdo, new Options(pk: 'item_id'));
        $row = $crud->create('things', ['name' => 'a']);
        $this->assertArrayHasKey('item_id', $row);
        $up = $crud->upsert('things', ['item_id' => $row['item_id'], 'name' => 'b']);
        $this->assertSame('b', $up['name']);
    }

    public function testDialectResolve(): void
    {
        $this->assertInstanceOf(SqliteDialect::class, resolveDialect('sqlite'));
        $this->assertInstanceOf(PostgresDialect::class, resolveDialect('postgres'));
        $this->assertInstanceOf(MySQLDialect::class, resolveDialect('mysql'));
        $this->assertSame('?', (new SqliteDialect())->placeholder(1));
        $this->assertSame('$2', (new PostgresDialect())->placeholder(2));
        $this->assertFalse((new MySQLDialect())->supportsInsertReturning());
        $this->assertSame('"a""b"', (new SqliteDialect())->quoteIdent('a"b'));
        $this->assertSame('`a``b`', (new MySQLDialect())->quoteIdent('a`b'));
    }
}
