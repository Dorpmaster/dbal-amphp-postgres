<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Runtime;

/** @internal */
enum ConnectionState: string
{
    case IDLE = 'idle';
    case PINNED_BY_TRANSACTION = 'pinned_by_transaction';
    case PINNED_BY_RESULT = 'pinned_by_result';
    case BROKEN = 'broken';

    public function isPinned(): bool
    {
        return match ($this) {
            self::PINNED_BY_TRANSACTION, self::PINNED_BY_RESULT => true,
            self::IDLE, self::BROKEN => false,
        };
    }

    public function canBeReleased(): bool
    {
        return $this === self::IDLE;
    }

    public function isBroken(): bool
    {
        return $this === self::BROKEN;
    }
}
