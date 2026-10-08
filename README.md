# b4moss/crudian (PHP)

CRUD facade matching the JavaScript / Go contract, for **PDO** and **libSQL** (technical preview).

```bash
composer require b4moss/crudian
```

Requires **PHP 8.3+** and `ext-pdo`.

## PDO

```php
use B4moss\Crudian\WhereBuilder;
use function B4moss\Crudian\Pdo\createCrud;

$pdo = new PDO('sqlite::memory:');
$crud = createCrud($pdo); // inject; does not open connections

$row = $crud->create('items', ['name' => 'alpha', 'score' => 1]);
$page = $crud->search('items', [
    'where' => WhereBuilder::create()->eq('name', 'alpha'),
    'limit' => 20,
]);
```

Dialect is inferred from the PDO driver (`sqlite` / `pgsql` / `mysql`), or set via `Options`:

```php
use B4moss\Crudian\Options;
use B4moss\Crudian\PostgresDialect;

$crud = createCrud($pdo, new Options(dialect: new PostgresDialect(), pk: 'id'));
```

## libSQL (technical preview)

Uses the official SDK (`turso/libsql`) behind a thin Executor. Requires `ext-ffi`. Remote Turso Cloud E2E is out of scope.

```php
use function B4moss\Crudian\Libsql\createCrud;

// $connection from turso/libsql (or a PDO-compatible stand-in in tests)
$crud = createCrud($connection);
```

## Contract

Methods: `create` / `read` / `update` / `delete` / `search` / `list` / `count` / `exists` / `upsert` / `duplicate` / `bulk*` / `transaction`.  
Acceptance: [`docs/tests/v0.12.0.md`](../../docs/tests/v0.12.0.md). Design: [`docs/plans/php-module.md`](../../docs/plans/php-module.md).

Laravel is **not** supported.

## Test / lint

```bash
composer install
composer test
composer phpstan
```

## Release

Version file: `VERSION` (not `composer.json`).  
Monorepo git tag: **`packages/php/vX.Y.Z`**.  
Packagist dist: [b4moss/crudian-php](https://github.com/b4moss/crudian-php) tagged **`vX.Y.Z`** by that repo’s sync workflow (`GITHUB_TOKEN`, after monorepo Release).
