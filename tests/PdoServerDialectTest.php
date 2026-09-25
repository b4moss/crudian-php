<?php

declare(strict_types=1);

namespace B4moss\Crudian\Tests;

use B4moss\Crudian\Options;
use B4moss\Crudian\WhereBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use function B4moss\Crudian\Pdo\createCrud;

/**
 * Optional server dialect smoke. Skips when DATABASE_URL_* is unset.
 */
final class PdoServerDialectTest extends TestCase
{
    public function testPostgresSmoke(): void
    {
        $url = getenv('DATABASE_URL_POSTGRES') ?: '';
        if ($url === '') {
            $this->markTestSkipped('DATABASE_URL_POSTGRES not set');
        }
        $pdo = new PDO($this->toPdoDsn($url, 'pgsql'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS items');
        $pdo->exec('CREATE TABLE items (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            score INTEGER NOT NULL
        )');
        $crud = createCrud($pdo, new Options(driver: 'postgres'));
        $row = $crud->create('items', ['name' => 'pg', 'score' => 1]);
        $this->assertSame('pg', $row['name']);
        $this->assertSame(1, $crud->count('items'));
        $updated = $crud->update('items', ['score' => 2], [
            'where' => WhereBuilder::create()->eq('name', 'pg'),
        ]);
        $this->assertNotNull($updated);
        $this->assertSame(2, (int) $updated['score']);
    }

    public function testMysqlSmoke(): void
    {
        $url = getenv('DATABASE_URL_MYSQL') ?: '';
        if ($url === '') {
            $this->markTestSkipped('DATABASE_URL_MYSQL not set');
        }
        $pdo = new PDO($this->toPdoDsn($url, 'mysql'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP TABLE IF EXISTS items');
        $pdo->exec('CREATE TABLE items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name TEXT NOT NULL,
            score INT NOT NULL
        )');
        $crud = createCrud($pdo, new Options(driver: 'mysql'));
        $row = $crud->create('items', ['name' => 'my', 'score' => 1]);
        $this->assertSame('my', $row['name']);
        $this->assertSame(1, $crud->count('items'));
    }

    private function toPdoDsn(string $url, string $driver): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            $this->fail('invalid DATABASE_URL');
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db = ltrim($parts['path'] ?? '/crudian', '/');
        $user = $parts['user'] ?? 'crudian';
        $pass = $parts['pass'] ?? 'crudian';
        if ($driver === 'pgsql') {
            return "pgsql:host={$host};port={$port};dbname={$db};user={$user};password={$pass}";
        }

        return "mysql:host={$host};port={$port};dbname={$db};user={$user};password={$pass}";
    }
}
