<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Driver;

use Amp\Postgres\PostgresQueryError;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriverException;
use Dorpmaster\DbalAmpPostgres\Tests\Support\FakeExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Tests\Support\RecordingObserver;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AmpPgConnectionTest extends TestCase
{
    public function testBeginTransactionPinsLease(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection([], executionExecutor: $executor);

        $connection->beginTransaction();

        self::assertNotNull($connection->currentTransactionLease());
        self::assertSame(1, $connection->leaseManager()->transactionScope()->depth());
        self::assertTrue($connection->currentTransactionLease()->isTransactionPinned());
    }

    public function testNestedBeginTransactionKeepsSameLease(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection([], executionExecutor: $executor);

        $connection->beginTransaction();
        $lease = $connection->currentTransactionLease();
        $connection->beginTransaction();

        self::assertSame($lease, $connection->currentTransactionLease());
        self::assertSame(2, $connection->leaseManager()->transactionScope()->depth());
    }

    public function testNestedCommitDoesNotReleaseLeaseTooEarly(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection([], executionExecutor: $executor);

        $connection->beginTransaction();
        $connection->beginTransaction();
        $lease = $connection->currentTransactionLease();

        $connection->commit();

        self::assertSame($lease, $connection->currentTransactionLease());
        self::assertFalse($lease->isReleased());
        self::assertSame(1, $connection->leaseManager()->transactionScope()->depth());
    }

    public function testOutermostCommitReleasesLease(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection([], executionExecutor: $executor);

        $connection->beginTransaction();
        $lease = $connection->currentTransactionLease();

        $connection->commit();

        self::assertNull($connection->currentTransactionLease());
        self::assertTrue($lease->isReleased());
        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
    }

    public function testOutermostRollbackReleasesLease(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection([], executionExecutor: $executor);

        $connection->beginTransaction();
        $lease = $connection->currentTransactionLease();

        $connection->rollBack();

        self::assertNull($connection->currentTransactionLease());
        self::assertTrue($lease->isReleased());
        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
    }

    public function testQueryReturnsResultAndPinsLeaseUntilFree(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryResult(new ExecutionResult(rows: [['id' => 1, 'name' => 'alpha']]));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $result = $connection->query('SELECT 1');

        self::assertSame(1, $connection->leaseManager()->activeLeaseCount());
        self::assertSame(1, $connection->leaseManager()->activeResultCount());
        self::assertSame(['id' => 1, 'name' => 'alpha'], $result->fetchAssociative());

        $result->free();

        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
        self::assertSame(0, $connection->leaseManager()->activeResultCount());
    }

    public function testExecReleasesLeaseImmediately(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryResult(new ExecutionResult(affectedRows: 3));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        self::assertSame(3, $connection->exec('UPDATE demo SET value = 1'));
        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
        self::assertSame(1, $executor->callCount());
        self::assertSame('command', $executor->calls()[0]['method']);
    }

    public function testExecutionExceptionDoesNotLeaveDanglingLeaseState(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryException(new RuntimeException('boom'));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $this->expectException(AmpPgDriverException::class);
        $this->expectExceptionMessage('boom');

        try {
            $connection->query('SELECT 1');
        } finally {
            self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
            self::assertSame(0, $connection->leaseManager()->activeResultCount());
            self::assertNull($connection->currentTransactionLease());
        }
    }

    public function testPreparedResultPinnedLeaseSurvivesUntilFree(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(rows: [['id' => 2]]));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $statement = $connection->prepare('SELECT * FROM demo');
        $result = $statement->execute();

        self::assertSame(1, $connection->leaseManager()->activeLeaseCount());
        $result->free();
        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
    }

    public function testGetServerVersionUsesExecutor(): void
    {
        $connection = new AmpPgConnection(params: [], executionExecutor: new FakeExecutionExecutor());

        self::assertSame('17.2', $connection->getServerVersion());
    }

    public function testLastInsertIdUsesPinnedTransactionLease(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $executor->queueQueryResult(new ExecutionResult(rows: [['last_insert_id' => '42']]));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $connection->beginTransaction();

        self::assertSame('42', $connection->lastInsertId());
        self::assertSame('SELECT LASTVAL() AS last_insert_id', $executor->calls()[1]['sql']);
        self::assertSame('query', $executor->calls()[1]['method']);
    }

    public function testLastInsertIdRequiresActiveTransaction(): void
    {
        $connection = new AmpPgConnection(params: [], executionExecutor: new FakeExecutionExecutor());

        $this->expectException(AmpPgDriverException::class);
        $this->expectExceptionMessage('requires an active transaction');

        $connection->lastInsertId();
    }

    public function testCloseClearsTransactionStateAndMakesConnectionUnusable(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueTransactionHandle();
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $connection->beginTransaction();
        $connection->close();

        self::assertTrue($connection->isClosed());
        self::assertNull($connection->currentTransactionLease());
        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
        self::assertSame(0, $connection->leaseManager()->transactionScope()->depth());

        $this->expectException(AmpPgDriverException::class);
        $this->expectExceptionMessage('AmpPgConnection is closed');

        $connection->query('SELECT 1');
    }

    public function testLateResultFreeAfterCloseIsSafe(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryResult(new ExecutionResult(rows: [['id' => 1]]));
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);

        $result = $connection->query('SELECT 1');
        $connection->close();
        $result->free();

        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
        self::assertTrue($connection->isClosed());
    }

    public function testOnCloseCallbackIsInvoked(): void
    {
        $connection = new AmpPgConnection(params: [], executionExecutor: new FakeExecutionExecutor());
        $closed = false;

        $connection->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $connection->close();

        self::assertTrue($closed);
    }

    public function testObserverReceivesExecutionLifecycleWithoutParameterValues(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryResult(new ExecutionResult(rows: [['id' => 1]]));
        $observer = new RecordingObserver();
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor, observer: $observer);

        $connection->query('SELECT 1')->free();

        self::assertSame('query.started', $observer->events()[0]['event']);
        self::assertSame('query.finished', $observer->events()[1]['event']);
        self::assertSame('SELECT 1', $observer->events()[0]['context']['sql']);
        self::assertSame(0, $observer->events()[0]['context']['parameter_count']);
        self::assertArrayNotHasKey('params', $observer->events()[0]['context']);
    }

    public function testFatalSessionQueryErrorMarksLeaseBrokenAndEmitsObserverEvent(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryException(
            new PostgresQueryError(
                'terminating connection due to administrator command',
                ['sqlstate' => '57P01'],
                'SELECT 1',
            ),
        );
        $observer = new RecordingObserver();
        $connection = new AmpPgConnection(params: [], executionExecutor: $executor, observer: $observer);

        $this->expectException(AmpPgDriverException::class);

        try {
            $connection->query('SELECT 1');
        } finally {
            self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
            self::assertSame('query.failed', $observer->events()[1]['event']);
            self::assertTrue($observer->events()[1]['context']['fatal_session_error']);
            self::assertSame('lease.marked_broken', $observer->events()[2]['event']);
        }
    }
}
