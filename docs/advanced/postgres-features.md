# PostgreSQL features

## Supported subset

The validated PostgreSQL-specific subset currently includes:

- transaction-scoped `lastInsertId()`
- JSON / JSONB through explicit JSON strings or DBAL type conversion
- one-dimensional scalar array parameters
- `bytea` round-trips

## `lastInsertId()`

`lastInsertId()` is intentionally scoped to active transactions.

Why:

- PostgreSQL identity lookup is session-sensitive
- the driver uses a pool
- outside an active transaction, there is no safe guarantee that the next operation will hit the same backend session

Inside an active transaction, the driver reads `LASTVAL()` on the pinned backend connection.

## JSON / JSONB

The driver does not guess JSON from arbitrary PHP arrays at the driver layer.

Supported approaches:

- pass a JSON string explicitly
- rely on higher-level DBAL type conversion such as `Types::JSON` or `Types::JSONB`

## Arrays

Supported PostgreSQL arrays:

- `list<string>`
- `list<int>`
- `list<bool>`

Unsupported:

- multidimensional arrays
- mixed scalar arrays
- associative arrays
- arrays with `null` elements

## `bytea`

Binary payloads are passed through PostgreSQL `bytea` handling and round-trip as binary strings.
