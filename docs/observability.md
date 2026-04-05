# Observability

## Overview

The driver exposes an optional observer hook for lightweight lifecycle visibility.

Provide an implementation of `Dorpmaster\DbalAmpPostgres\Observability\DriverObserver` through the `dbal_amp_pg_observer` connection parameter.

## Event model

Observed events include:

- `query.started`
- `query.finished`
- `query.failed`
- `transaction.began`
- `transaction.committed`
- `transaction.rolled_back`
- `lease.marked_broken`
- `pool.shutdown`
- `connection.closed`

## Sensitive data policy

Observer payloads intentionally avoid parameter values.

The emitted context is limited to metadata such as:

- SQL text
- parameter count
- row count / affected rows
- exception class
- SQLSTATE when available

This keeps the default hook useful without turning it into a data-leak vector.
