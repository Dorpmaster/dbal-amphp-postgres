<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Runtime;

use Dorpmaster\DbalAmpPostgres\Runtime\ConnectionState;
use PHPUnit\Framework\TestCase;

final class ConnectionStateTest extends TestCase
{
    public function testIdleStateIsReleasable(): void
    {
        self::assertTrue(ConnectionState::IDLE->canBeReleased());
        self::assertFalse(ConnectionState::IDLE->isPinned());
    }

    public function testPinnedStatesAreNotReleasable(): void
    {
        self::assertTrue(ConnectionState::PINNED_BY_TRANSACTION->isPinned());
        self::assertTrue(ConnectionState::PINNED_BY_RESULT->isPinned());
        self::assertFalse(ConnectionState::PINNED_BY_TRANSACTION->canBeReleased());
        self::assertFalse(ConnectionState::PINNED_BY_RESULT->canBeReleased());
    }

    public function testBrokenStateIsReportedExplicitly(): void
    {
        self::assertTrue(ConnectionState::BROKEN->isBroken());
        self::assertFalse(ConnectionState::BROKEN->canBeReleased());
    }
}
