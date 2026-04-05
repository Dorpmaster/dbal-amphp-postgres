<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Transport;

use Dorpmaster\DbalAmpPostgres\Runtime\ConnectionState;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\LeaseAlreadyReleased;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\LeasePinningError;
use PHPUnit\Framework\TestCase;

final class ConnectionLeaseTest extends TestCase
{
    public function testNewLeaseStartsActiveAndReleasable(): void
    {
        $lease = new ConnectionLease('lease-1');

        self::assertSame('lease-1', $lease->id());
        self::assertFalse($lease->isReleased());
        self::assertFalse($lease->isBroken());
        self::assertFalse($lease->isPinned());
        self::assertTrue($lease->canBeReleased());
        self::assertSame(ConnectionState::IDLE, $lease->state());
    }

    public function testTransactionPinningIsTrackedExplicitly(): void
    {
        $lease = new ConnectionLease('lease-1');

        $lease->pinForTransaction();

        self::assertTrue($lease->isTransactionPinned());
        self::assertTrue($lease->isPinned());
        self::assertFalse($lease->canBeReleased());
        self::assertSame(ConnectionState::PINNED_BY_TRANSACTION, $lease->state());

        $lease->unpinFromTransaction();

        self::assertFalse($lease->isPinned());
        self::assertTrue($lease->canBeReleased());
    }

    public function testResultPinningTracksReferenceCount(): void
    {
        $lease = new ConnectionLease('lease-1');

        $lease->pinForResult();
        $lease->pinForResult();

        self::assertTrue($lease->isResultPinned());
        self::assertSame(2, $lease->resultPinCount());
        self::assertSame(ConnectionState::PINNED_BY_RESULT, $lease->state());

        $lease->unpinFromResult();
        self::assertSame(1, $lease->resultPinCount());

        $lease->unpinFromResult();
        self::assertFalse($lease->isResultPinned());
        self::assertTrue($lease->canBeReleased());
    }

    public function testReleaseFailsWhileLeaseIsPinned(): void
    {
        $lease = new ConnectionLease('lease-1');
        $lease->pinForTransaction();

        $this->expectException(LeasePinningError::class);
        $lease->release();
    }

    public function testReleasedLeaseRejectsFurtherTransitions(): void
    {
        $lease = new ConnectionLease('lease-1');
        $lease->release();

        self::assertTrue($lease->isReleased());

        $this->expectException(LeaseAlreadyReleased::class);
        $lease->pinForResult();
    }

    public function testInvalidUnpinTransitionsFailExplicitly(): void
    {
        $lease = new ConnectionLease('lease-1');

        $this->expectException(LeasePinningError::class);
        $lease->unpinFromTransaction();
    }

    public function testBrokenLeaseCanBeMarkedAndReleasedTerminally(): void
    {
        $lease = new ConnectionLease('lease-1');

        $lease->markBroken();

        self::assertTrue($lease->isBroken());
        self::assertSame(ConnectionState::BROKEN, $lease->state());
        self::assertTrue($lease->canBeReleased());

        $lease->release();

        self::assertTrue($lease->isReleased());
    }

    public function testLeaseCanStoreTransportHandle(): void
    {
        $lease = new ConnectionLease('lease-1');
        $handle = new class () {
        };

        $lease->attachTransportHandle($handle);

        self::assertSame($handle, $lease->transportHandle());
        self::assertSame($handle, $lease->detachTransportHandle());
        self::assertNull($lease->transportHandle());
    }
}
