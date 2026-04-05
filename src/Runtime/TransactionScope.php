<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Runtime;

use LogicException;

/** @internal */
final class TransactionScope
{
    public function __construct(private int $depth = 0)
    {
        if ($depth < 0) {
            throw new LogicException('Transaction depth cannot be negative.');
        }
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function begin(): void
    {
        $this->increase();
    }

    public function commitLevel(): void
    {
        $this->decrease();
    }

    public function rollbackLevel(): void
    {
        $this->decrease();
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function isOutermost(): bool
    {
        return $this->depth === 1;
    }

    public function willBecomeInactiveAfterDecrease(): bool
    {
        if ($this->depth === 0) {
            throw new LogicException('Cannot inspect transaction decrease when scope is inactive.');
        }

        return $this->depth === 1;
    }

    public function increase(): void
    {
        $this->depth++;
    }

    public function decrease(): void
    {
        if ($this->depth === 0) {
            throw new LogicException('Cannot decrease transaction depth below zero.');
        }

        $this->depth--;
    }

    public function reset(): void
    {
        $this->depth = 0;
    }
}
