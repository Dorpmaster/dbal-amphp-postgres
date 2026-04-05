# Usage

## DBAL usage

Typical DBAL queries work through the standard Doctrine API:

```php
<?php

declare(strict_types=1);

$rows = $connection->fetchAllAssociative(
    'SELECT id, email FROM users ORDER BY id'
);
```

Prepared statements are supported through the normal DBAL and ORM execution flow.

## ORM usage

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

Validated baseline scenarios include:

- `find()`
- `persist()`
- `flush()`
- `remove()`
- DQL
- QueryBuilder
- baseline lazy-loading for validated `ManyToOne` and `OneToMany` cases

## Cleanup

Use deterministic cleanup in long-running runtimes:

```php
<?php

declare(strict_types=1);

$connection->close();
```

When using DBAL or ORM wrappers, prefer `AmpPgDbalConnection` as `wrapperClass` so `Connection::close()` propagates to the driver layer.
