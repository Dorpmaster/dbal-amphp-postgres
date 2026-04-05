# dorpmaster/dbal-amphp-postgres

PostgreSQL-only Doctrine DBAL driver backed by an AMPHP transport.

The library keeps the Doctrine DBAL and Doctrine ORM programming model synchronous while moving database I/O to a fiber-compatible `amphp/postgres` transport. It targets applications that want PostgreSQL-specific behavior, Doctrine integration, and predictable transaction ownership without introducing an async-native ORM.

## Key features

- PostgreSQL-only driver with a real `amphp/postgres` transport
- fiber-compatible I/O behind the standard Doctrine DBAL API
- Doctrine ORM baseline support, including `EntityManager`, `UnitOfWork`, DQL, QueryBuilder, and validated lazy-loading scenarios
- transaction pinning on one physical PostgreSQL connection
- explicit close and cleanup semantics for long-running processes
- lightweight optional observability hooks

## Quick start

Package publishing is not wired to Packagist yet. The expected install command is:

```bash
composer require dorpmaster/dbal-amphp-postgres
```

Until public distribution is configured, install it from a VCS or path repository.

Configure Doctrine DBAL with the custom driver and wrapper:

```php
<?php

declare(strict_types=1);

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDbalConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver;
use Doctrine\DBAL\DriverManager;

$connection = DriverManager::getConnection([
    'host' => '127.0.0.1',
    'port' => 5432,
    'dbname' => 'app',
    'user' => 'app',
    'password' => 'secret',
    'driverClass' => AmpPgDriver::class,
    'wrapperClass' => AmpPgDbalConnection::class,
]);
```

## Example usage

### Doctrine ORM bootstrap

```php
<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

$ormConfig = ORMSetup::createAttributeMetadataConfig(
    paths: [__DIR__ . '/src/Entity'],
    isDevMode: false,
);

$entityManager = new EntityManager($connection, $ormConfig);
```

### Simple query

```php
<?php

declare(strict_types=1);

$rows = $connection->fetchAllAssociative(
    'SELECT id, email FROM users ORDER BY id'
);
```

### Transaction

```php
<?php

declare(strict_types=1);

$connection->beginTransaction();

try {
    $connection->executeStatement(
        'INSERT INTO audit_log (message) VALUES (?)',
        ['created user'],
    );

    $connection->commit();
} catch (\Throwable $e) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    throw $e;
}
```

## Known limitations

- `EntityManager` is not concurrency-safe and must not be shared across parallel fibers
- streaming results are not supported
- the PostgreSQL type matrix is intentionally limited to the documented subset
- `lastInsertId()` is available only inside an active transaction on a pinned PostgreSQL connection
- after a SQL error inside a transaction, explicit rollback is required before more SQL can succeed on that connection

## Documentation

- [Getting started](docs/getting-started.md)
- [Installation](docs/installation.md)
- [Usage](docs/usage.md)
- [Transactions](docs/transactions.md)
- [Architecture](docs/architecture.md)
- [Observability](docs/observability.md)
- [Known limitations](docs/limitations.md)
- [PostgreSQL-specific features](docs/advanced/postgres-features.md)
- [Connection lifecycle](docs/advanced/connection-lifecycle.md)

## Project status

The driver is production-tested in internal workloads and is ready for staged external evaluation. Public packaging, broader compatibility validation, and ecosystem polish are still in progress.

## License

[MIT](LICENSE)
