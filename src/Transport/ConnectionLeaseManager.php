<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport;

use Dorpmaster\DbalAmpPostgres\Runtime\TransactionScope;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\InvalidLeaseState;

/** @internal */
final class ConnectionLeaseManager
{
    /** @var array<string, ConnectionLease> */
    private array $leases = [];

    private int $leaseSequence = 0;

    private ?ConnectionLease $transactionLease = null;

    private bool $closed = false;

    public function __construct(private readonly TransactionScope $transactionScope = new TransactionScope())
    {
    }

    public function acquireOperationLease(): ConnectionLease
    {
        $this->assertOpen();

        if ($this->transactionScope->isActive()) {
            if ($this->transactionLease === null) {
                throw new InvalidLeaseState('Transaction scope is active but no transaction lease is pinned.');
            }

            return $this->transactionLease;
        }

        $this->leaseSequence++;

        $lease = new ConnectionLease('lease-' . $this->leaseSequence);
        $this->leases[$lease->id()] = $lease;

        return $lease;
    }

    public function beginTransaction(): ConnectionLease
    {
        $this->assertOpen();

        if (! $this->transactionScope->isActive()) {
            $lease = $this->acquireOperationLease();
            $lease->pinForTransaction();
            $this->transactionLease = $lease;
        }

        $this->transactionScope->begin();

        return $this->transactionLease;
    }

    public function commitTransaction(): void
    {
        $this->assertOpen();
        $this->completeTransactionLevel(false);
    }

    public function rollbackTransaction(): void
    {
        $this->assertOpen();
        $this->completeTransactionLevel(true);
    }

    public function abortTransaction(): ?ConnectionLease
    {
        if ($this->closed) {
            return null;
        }

        if (! $this->transactionScope->isActive()) {
            return null;
        }

        $lease = $this->transactionLease;

        if ($lease === null) {
            throw new InvalidLeaseState('Transaction scope is active but no transaction lease is pinned.');
        }

        if ($lease->isTransactionPinned()) {
            $lease->unpinFromTransaction();
        }

        $this->transactionLease = null;
        $this->transactionScope->reset();
        $this->settleLease($lease);

        return $lease;
    }

    public function releaseOperationLease(ConnectionLease $lease): void
    {
        if ($this->closed) {
            return;
        }

        $this->assertManagedLease($lease);

        if ($lease->isReleased()) {
            return;
        }

        if ($lease === $this->transactionLease && $this->transactionScope->isActive()) {
            return;
        }

        $this->settleLease($lease);
    }

    public function pinLeaseForResult(ConnectionLease $lease): void
    {
        $this->assertOpen();
        $this->assertManagedLease($lease);
        $lease->pinForResult();
    }

    public function releaseResultLease(ConnectionLease $lease): void
    {
        if ($this->closed) {
            return;
        }

        $this->assertManagedLease($lease);
        $lease->unpinFromResult();
        $this->settleLease($lease);
    }

    public function markLeaseBroken(ConnectionLease $lease): void
    {
        if ($this->closed) {
            return;
        }

        $this->assertManagedLease($lease);
        $lease->markBroken();
        $this->settleLease($lease);
    }

    public function currentTransactionLease(): ?ConnectionLease
    {
        if ($this->closed) {
            return null;
        }

        return $this->transactionLease;
    }

    public function transactionScope(): TransactionScope
    {
        return $this->transactionScope;
    }

    public function activeResultCount(): int
    {
        $activeResultCount = 0;

        foreach ($this->leases as $lease) {
            $activeResultCount += $lease->resultPinCount();
        }

        return $activeResultCount;
    }

    public function activeLeaseCount(): int
    {
        return count($this->leases);
    }

    /**
     * @return list<ConnectionLease>
     */
    public function activeLeases(): array
    {
        return array_values($this->leases);
    }

    public function hasLease(ConnectionLease $lease): bool
    {
        return isset($this->leases[$lease->id()]);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        foreach ($this->leases as $lease) {
            $lease->forceRelease();
        }

        $this->leases = [];
        $this->transactionLease = null;
        $this->transactionScope->reset();
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function completeTransactionLevel(bool $rollback): void
    {
        $outermost = $this->transactionScope->willBecomeInactiveAfterDecrease();

        if ($outermost && $this->transactionLease === null) {
            throw new InvalidLeaseState('Outermost transaction completion requires a pinned lease.');
        }

        if ($outermost) {
            $lease = $this->transactionLease;
            $lease->unpinFromTransaction();
            $this->transactionLease = null;
        }

        if ($rollback) {
            $this->transactionScope->rollbackLevel();
        } else {
            $this->transactionScope->commitLevel();
        }

        if (! $outermost) {
            return;
        }

        $this->settleLease($lease);
    }

    private function settleLease(ConnectionLease $lease): void
    {
        if ($lease->isReleased()) {
            unset($this->leases[$lease->id()]);

            return;
        }

        if (! $lease->canBeReleased()) {
            return;
        }

        $lease->release();
        unset($this->leases[$lease->id()]);
    }

    private function assertManagedLease(ConnectionLease $lease): void
    {
        if (! isset($this->leases[$lease->id()])) {
            throw new InvalidLeaseState('Lease "' . $lease->id() . '" is not managed by this connection.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new InvalidLeaseState('Connection lease manager has already been closed.');
        }
    }
}
