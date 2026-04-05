<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Transport;

use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLeaseManager;
use PHPUnit\Framework\TestCase;

final class ConnectionLeaseManagerTest extends TestCase
{
    public function testAcquireAndReleaseOperationLeaseOutsideTransaction(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->acquireOperationLease();

        self::assertSame(1, $manager->activeLeaseCount());
        self::assertTrue($manager->hasLease($lease));

        $manager->releaseOperationLease($lease);

        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testBeginTransactionPinsOneLeaseAndNestedBeginReusesIt(): void
    {
        $manager = new ConnectionLeaseManager();

        $outerLease = $manager->beginTransaction();
        $innerLease = $manager->beginTransaction();

        self::assertSame($outerLease, $innerLease);
        self::assertSame($outerLease, $manager->acquireOperationLease());
        self::assertSame(2, $manager->transactionScope()->depth());
        self::assertTrue($outerLease->isTransactionPinned());
        self::assertSame(1, $manager->activeLeaseCount());
    }

    public function testOutermostCommitReleasesLeaseWithoutResultPin(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->beginTransaction();
        $manager->beginTransaction();

        $manager->commitTransaction();

        self::assertSame($lease, $manager->currentTransactionLease());
        self::assertSame(1, $manager->transactionScope()->depth());
        self::assertSame(1, $manager->activeLeaseCount());

        $manager->commitTransaction();

        self::assertNull($manager->currentTransactionLease());
        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testOutermostRollbackReleasesLeaseWithoutResultPin(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->beginTransaction();

        $manager->rollbackTransaction();

        self::assertNull($manager->currentTransactionLease());
        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testResultPinBlocksReleaseUntilFreed(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->acquireOperationLease();
        $manager->pinLeaseForResult($lease);
        $manager->releaseOperationLease($lease);

        self::assertSame(1, $manager->activeLeaseCount());
        self::assertTrue($manager->hasLease($lease));
        self::assertFalse($lease->isReleased());

        $manager->releaseResultLease($lease);

        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testResultPinnedLeaseSurvivesTransactionCompletionUntilFree(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->beginTransaction();
        $manager->pinLeaseForResult($lease);

        $manager->commitTransaction();

        self::assertNull($manager->currentTransactionLease());
        self::assertSame(1, $manager->activeLeaseCount());
        self::assertFalse($lease->isReleased());
        self::assertTrue($lease->isResultPinned());

        $manager->releaseResultLease($lease);

        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testBrokenLeaseIsNotReusableAndIsDiscardedWhenNotPinned(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->acquireOperationLease();
        $manager->markLeaseBroken($lease);

        self::assertTrue($lease->isBroken());
        self::assertTrue($lease->isReleased());
        self::assertSame(0, $manager->activeLeaseCount());

        $newLease = $manager->acquireOperationLease();

        self::assertNotSame($lease, $newLease);
    }

    public function testBrokenTransactionLeaseRemainsUntilPinsAreGone(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->beginTransaction();
        $manager->pinLeaseForResult($lease);
        $manager->markLeaseBroken($lease);

        self::assertTrue($lease->isBroken());
        self::assertSame(1, $manager->activeLeaseCount());

        $manager->rollbackTransaction();

        self::assertSame(1, $manager->activeLeaseCount());
        self::assertFalse($lease->isReleased());

        $manager->releaseResultLease($lease);

        self::assertSame(0, $manager->activeLeaseCount());
        self::assertTrue($lease->isReleased());
    }

    public function testAbortTransactionClearsPinnedLease(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->beginTransaction();
        $manager->abortTransaction();

        self::assertNull($manager->currentTransactionLease());
        self::assertSame(0, $manager->transactionScope()->depth());
        self::assertTrue($lease->isReleased());
        self::assertSame(0, $manager->activeLeaseCount());
    }

    public function testCloseForceReleasesAllManagedLeasesAndIgnoresLateRelease(): void
    {
        $manager = new ConnectionLeaseManager();

        $lease = $manager->acquireOperationLease();
        $manager->pinLeaseForResult($lease);
        $manager->close();
        $manager->releaseResultLease($lease);

        self::assertTrue($lease->isReleased());
        self::assertTrue($manager->isClosed());
        self::assertSame(0, $manager->activeLeaseCount());
        self::assertNull($manager->currentTransactionLease());
    }
}
