# Transactions

## Transaction model

The driver pins one physical PostgreSQL connection for the duration of an active transaction.

- `beginTransaction()` at depth `0` acquires and pins one backend connection
- nested Doctrine DBAL transactions use savepoints on that same connection
- the connection is released only when the outermost transaction ends and no result pin remains

## Typical usage

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

## Recovery after failure

PostgreSQL moves a transaction into aborted state after many SQL errors. In practice that means:

- a failing statement inside a transaction does not automatically clean the transaction up
- explicit `rollBack()` is required before more SQL can succeed on that connection
- after a failed ORM flush, rollback first and create a fresh `EntityManager` when recovery is needed
