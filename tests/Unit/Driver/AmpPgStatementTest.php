<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Driver;

use Amp\Postgres\PostgresArray;
use Amp\Postgres\PostgresByteA;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriverException;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgStatement;
use Dorpmaster\DbalAmpPostgres\Tests\Support\FakeExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionResult;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AmpPgStatementTest extends TestCase
{
    public function testPositionalParamsExecuteThroughPreparedPipeline(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(rows: [['id' => 1, 'name' => 'alpha']]));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('SELECT * FROM demo WHERE id = ? AND active = ?');
        $statement->bindValue(1, 5, ParameterType::INTEGER);
        $statement->bindValue(2, true, ParameterType::BOOLEAN);

        $result = $statement->execute();

        self::assertSame(['id' => 1, 'name' => 'alpha'], $result->fetchAssociative());
        self::assertSame(
            [
                'method' => 'statement',
                'sql' => 'SELECT * FROM demo WHERE id = ? AND active = ?',
                'params' => [5, true],
                'leaseId' => 'lease-1',
            ],
            $executor->calls()[0],
        );
    }

    public function testNamedParamsExecuteThroughPreparedPipeline(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(rows: [['id' => 7, 'name' => 'beta']]));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('SELECT * FROM demo WHERE id = :id AND name = :name');
        $statement->bindValue('id', 7, ParameterType::INTEGER);
        $statement->bindValue('name', 'beta', ParameterType::STRING);

        $result = $statement->execute();

        self::assertSame([7, 'beta'], $result->fetchNumeric());
        self::assertSame(
            [
                'method' => 'statement',
                'sql' => 'SELECT * FROM demo WHERE id = :id AND name = :name',
                'params' => ['id' => 7, 'name' => 'beta'],
                'leaseId' => 'lease-1',
            ],
            $executor->calls()[0],
        );
    }

    public function testBindParamUsesCurrentVariableValueAtExecutionTime(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(rows: [['id' => 9]]));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('SELECT * FROM demo WHERE id = ?');
        self::assertInstanceOf(AmpPgStatement::class, $statement);
        $value = 1;
        $statement->bindParam(1, $value, ParameterType::INTEGER);
        $value = 9;

        $statement->execute()->free();

        self::assertSame(9, $executor->calls()[0]['params'][0]);
    }

    public function testStatementResultKeepsLeasePinnedUntilFree(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(rows: [['id' => 1]]));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('SELECT * FROM demo');
        $result = $statement->execute();

        self::assertSame(1, $connection->leaseManager()->activeLeaseCount());
        self::assertSame(1, $connection->leaseManager()->activeResultCount());

        $result->free();

        self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
    }

    public function testStatementConvertsPostgresArrayAndByteaParameters(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementResult(new ExecutionResult(affectedRows: 1));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('INSERT INTO demo (tags, payload) VALUES (?, ?)');
        $statement->bindValue(1, ['alpha', 'beta'], ParameterType::STRING);
        $statement->bindValue(2, 'binary', ParameterType::BINARY);
        $statement->execute()->free();

        self::assertInstanceOf(PostgresArray::class, $executor->calls()[0]['params'][0]);
        self::assertInstanceOf(PostgresByteA::class, $executor->calls()[0]['params'][1]);
    }

    public function testStatementExecutionExceptionLeavesStateConsistent(): void
    {
        $executor = new FakeExecutionExecutor();
        $executor->queueStatementException(new RuntimeException('boom'));

        $connection = new AmpPgConnection(params: [], executionExecutor: $executor);
        $statement = $connection->prepare('SELECT * FROM demo');

        $this->expectException(AmpPgDriverException::class);
        $this->expectExceptionMessage('boom');

        try {
            $statement->execute();
        } finally {
            self::assertSame(0, $connection->leaseManager()->activeLeaseCount());
            self::assertSame(0, $connection->leaseManager()->activeResultCount());
        }
    }
}
