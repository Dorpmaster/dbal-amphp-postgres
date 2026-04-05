<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Support;

use Dorpmaster\DbalAmpPostgres\Tests\Support\FakeExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FakeExecutionExecutorTest extends TestCase
{
    public function testItRecordsQueryCalls(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueQueryResult(new ExecutionResult(rows: [['id' => 1]]));
        $lease = new ConnectionLease('lease-1');

        $result = $executor->executeQuery('SELECT 1', $lease);

        self::assertSame([['id' => 1]], $result->rows());
        self::assertSame(1, $executor->callCount());
        self::assertSame(
            [
                'method' => 'query',
                'sql' => 'SELECT 1',
                'params' => [],
                'leaseId' => 'lease-1',
            ],
            $executor->calls()[0],
        );
    }

    public function testItRecordsPreparedCallsAndCanThrowConfiguredException(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementException(new RuntimeException('boom'));
        $lease = new ConnectionLease('lease-7');

        $this->expectException(RuntimeException::class);

        try {
            $executor->executeStatement('SELECT * FROM demo WHERE id = ?', [1 => 7], $lease);
        } finally {
            self::assertSame(1, $executor->callCount());
            self::assertSame(
                [
                    'method' => 'statement',
                    'sql' => 'SELECT * FROM demo WHERE id = ?',
                    'params' => [1 => 7],
                    'leaseId' => 'lease-7',
                ],
                $executor->calls()[0],
            );
        }
    }

    public function testItCanRecordTransactionLifecycleCalls(): void
    {
        $executor = new FakeExecutionExecutor();
        $lease = new ConnectionLease('lease-9');
        $transactionHandle = $executor->queueTransactionHandle();

        self::assertSame($transactionHandle, $executor->beginTransaction($lease));
        $executor->commitTransaction($transactionHandle);

        self::assertSame('begin-transaction', $executor->calls()[0]['method']);
        self::assertSame('commit-transaction', $executor->calls()[1]['method']);
    }

    public function testItTracksShutdown(): void
    {
        $executor = new FakeExecutionExecutor();

        self::assertFalse($executor->isShutdown());

        $executor->shutdown();

        self::assertTrue($executor->isShutdown());
    }
}
