# Architecture

## Overview

The library implements a custom Doctrine DBAL driver for PostgreSQL and connects it to a real `amphp/postgres` transport.

The main idea is:

- keep the external Doctrine DBAL and ORM API synchronous
- perform actual database I/O through a fiber-compatible AMPHP transport
- keep PostgreSQL-specific behavior inside the driver package

## Main layers

- `Driver\AmpPgDriver` creates DBAL connections
- `Driver\AmpPgConnection` orchestrates execution, transactions, cleanup, and error handling
- `Driver\AmpPgStatement` handles parameter binding and prepared execution
- `Driver\AmpPgResult` materializes buffered DBAL results
- `Transport\Execution\AmpPgExecutionExecutor` executes queries against `amphp/postgres`

## Design choices

- PostgreSQL-only scope
- buffered result model
- one pinned connection per active transaction
- deterministic lease and cleanup semantics
- optional lightweight observability hooks

For deeper implementation notes, see [advanced/connection-lifecycle.md](advanced/connection-lifecycle.md) and [internal/design.md](internal/design.md).
