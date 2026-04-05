<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport;

use Dorpmaster\DbalAmpPostgres\Runtime\ConnectionState;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\LeaseAlreadyReleased;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\LeasePinningError;

/** @internal */
final class ConnectionLease
{
    private ?object $transportHandle = null;

    private bool $released = false;

    private bool $broken = false;

    private bool $transactionPinned = false;

    private int $resultPinCount = 0;

    public function __construct(private readonly string $identifier)
    {
    }

    public function id(): string
    {
        return $this->identifier;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function state(): ConnectionState
    {
        if ($this->broken) {
            return ConnectionState::BROKEN;
        }

        if ($this->transactionPinned) {
            return ConnectionState::PINNED_BY_TRANSACTION;
        }

        if ($this->resultPinCount > 0) {
            return ConnectionState::PINNED_BY_RESULT;
        }

        return ConnectionState::IDLE;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    public function transportHandle(): ?object
    {
        return $this->transportHandle;
    }

    public function attachTransportHandle(object $transportHandle): void
    {
        $this->assertNotReleased();
        $this->transportHandle = $transportHandle;
    }

    public function detachTransportHandle(): ?object
    {
        $this->assertNotReleased();

        $transportHandle = $this->transportHandle;
        $this->transportHandle = null;

        return $transportHandle;
    }

    public function markBroken(): void
    {
        $this->assertNotReleased();

        $this->broken = true;
    }

    public function release(): void
    {
        $this->assertNotReleased();

        if ($this->transactionPinned) {
            throw new LeasePinningError('Cannot release a transaction-pinned lease.');
        }

        if ($this->resultPinCount > 0) {
            throw new LeasePinningError('Cannot release a result-pinned lease.');
        }

        $this->transportHandle = null;

        $this->released = true;
    }

    public function forceRelease(): void
    {
        if ($this->released) {
            return;
        }

        $this->transportHandle = null;
        $this->transactionPinned = false;
        $this->resultPinCount = 0;
        $this->released = true;
    }

    public function pinForTransaction(): void
    {
        $this->assertNotReleased();

        if ($this->transactionPinned) {
            throw new LeasePinningError('Lease is already pinned by transaction.');
        }

        $this->transactionPinned = true;
    }

    public function unpinFromTransaction(): void
    {
        $this->assertNotReleased();

        if (! $this->transactionPinned) {
            throw new LeasePinningError('Lease is not pinned by transaction.');
        }

        $this->transactionPinned = false;
    }

    public function pinForResult(): void
    {
        $this->assertNotReleased();

        $this->resultPinCount++;
    }

    public function unpinFromResult(): void
    {
        $this->assertNotReleased();

        if ($this->resultPinCount === 0) {
            throw new LeasePinningError('Lease is not pinned by result.');
        }

        $this->resultPinCount--;
    }

    public function isTransactionPinned(): bool
    {
        return $this->transactionPinned;
    }

    public function isResultPinned(): bool
    {
        return $this->resultPinCount > 0;
    }

    public function resultPinCount(): int
    {
        return $this->resultPinCount;
    }

    public function isPinned(): bool
    {
        return $this->transactionPinned || $this->resultPinCount > 0;
    }

    public function canBeReleased(): bool
    {
        return ! $this->released && ! $this->isPinned();
    }

    private function assertNotReleased(): void
    {
        if ($this->released) {
            throw new LeaseAlreadyReleased('Lease "' . $this->identifier . '" has already been released.');
        }
    }
}
