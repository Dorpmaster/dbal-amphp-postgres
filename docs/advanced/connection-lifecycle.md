# Connection lifecycle

## Lease model

The driver uses an internal lease model to track physical connection ownership.

- outside a transaction: one lease per operation
- inside a transaction: one pinned lease for the entire transaction scope
- while a result is alive: the lease remains pinned until the result is freed

## Cleanup

`AmpPgConnection::close()` is explicit and idempotent.

On close:

- active transaction handles are closed
- in-memory lease state is cleared
- the owned pool is shut down

Late `Result::free()` after close is safe and does not re-open the connection.

## Broken connection handling

The driver distinguishes between:

- ordinary SQL errors
- fatal transport or session errors

Fatal cases invalidate the lease, abort active transaction state, and prevent the broken connection from being reused.
