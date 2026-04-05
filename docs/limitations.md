# Known limitations

## Scope limits

- PostgreSQL-only driver target
- no streaming or unbuffered results
- no large objects API
- no full PostgreSQL type matrix

## Transaction and recovery limits

- `lastInsertId()` is supported only inside an active transaction on the pinned PostgreSQL connection
- `lastInsertId()` outside an active transaction is intentionally unsupported
- after a SQL error inside a transaction, explicit rollback is required before further SQL can succeed on that connection
- after a failed ORM flush, rollback plus a fresh `EntityManager` is the conservative recovery path

## ORM limits

- `EntityManager` is not concurrency-safe
- one `EntityManager` should be used per unit of work
- managed entities should not be shared across parallel fibers
- advanced proxy and collection edge cases are not claimed as fully proven

## PostgreSQL type limits

- JSON and JSONB are supported through explicit JSON strings or upstream DBAL type conversion
- PostgreSQL arrays are limited to one-dimensional scalar lists of strings, integers, and booleans
- multidimensional arrays, mixed scalar arrays, associative arrays, and arrays with `null` elements are unsupported

## Operational limits

- observer hooks are intentionally lightweight and are not a full metrics or tracing framework
- deterministic DBAL / ORM cleanup requires `Dorpmaster\DbalAmpPostgres\Driver\AmpPgDbalConnection` as `wrapperClass`
