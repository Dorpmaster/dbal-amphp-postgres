# Getting started

## What this library is

`dorpmaster/dbal-amphp-postgres` is a PostgreSQL-only Doctrine DBAL driver that uses `amphp/postgres` for transport while preserving the standard synchronous Doctrine programming model.

## When to use it

Use it when you want:

- Doctrine DBAL or Doctrine ORM
- PostgreSQL-specific behavior
- fiber-compatible database I/O
- explicit transaction ownership and cleanup semantics

## First steps

1. Build the runtime image used for local development:

```bash
make build
```

2. Install dependencies in the container:

```bash
make composer ARGS="dump-autoload"
```

3. Configure Doctrine DBAL with:

- `driverClass` = `Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver`
- `wrapperClass` = `Dorpmaster\DbalAmpPostgres\Driver\AmpPgDbalConnection`

4. Run the test suites:

```bash
make test
make integration-up
make test-integration
make integration-down
```
