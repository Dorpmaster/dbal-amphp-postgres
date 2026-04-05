# Installation

## Composer

The expected package install command is:

```bash
composer require dorpmaster/dbal-amphp-postgres
```

If the package is not yet published to Packagist, install it from a VCS or path repository.

## Runtime requirements

- PHP 8.5+
- `ext-pgsql`
- PostgreSQL

The project ships a Docker-based development runtime and integration test setup. For local development, use:

```bash
make build
```

## Doctrine DBAL configuration

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

`wrapperClass` is strongly recommended. It ensures `Connection::close()` also closes the underlying driver resources and pool.
