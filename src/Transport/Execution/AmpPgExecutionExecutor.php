<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport\Execution;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresTransaction;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;

use function count;

final readonly class AmpPgExecutionExecutor implements ExecutionExecutor
{
    public function __construct(private PostgresConnectionPool $pool)
    {
    }

    public function executeQuery(string $sql, ConnectionLease $lease): ExecutionResult
    {
        $result = $this->linkForLease($lease)->query($sql);

        return $this->materializeResult($result);
    }

    public function executeCommand(string $sql, ConnectionLease $lease): ExecutionResult
    {
        $result = $this->linkForLease($lease)->execute($sql);

        return $this->materializeResult($result);
    }

    public function executeStatement(string $sql, array $params, ConnectionLease $lease): ExecutionResult
    {
        $result = $this->linkForLease($lease)->execute($sql, $params);

        return $this->materializeResult($result);
    }

    public function beginTransaction(ConnectionLease $lease): object
    {
        return $this->pool->beginTransaction();
    }

    public function beginNestedTransaction(object $transactionHandle): object
    {
        /** @var PostgresTransaction $transactionHandle */
        return $transactionHandle->beginTransaction();
    }

    public function commitTransaction(object $transactionHandle): void
    {
        /** @var PostgresTransaction $transactionHandle */
        $transactionHandle->commit();
    }

    public function rollbackTransaction(object $transactionHandle): void
    {
        /** @var PostgresTransaction $transactionHandle */
        $transactionHandle->rollback();
    }

    public function fetchServerVersion(?ConnectionLease $lease = null): string
    {
        $result = $lease !== null
            ? $this->linkForLease($lease)->query('SHOW server_version')
            : $this->pool->query('SHOW server_version');

        $row = $result->fetchRow();

        return (string) ($row['server_version'] ?? '');
    }

    public function getNativeConnection(?ConnectionLease $lease = null): object
    {
        return $lease?->transportHandle() ?? $this->pool;
    }

    public function shutdown(): void
    {
        if (! $this->pool->isClosed()) {
            $this->pool->close();
        }
    }

    private function linkForLease(ConnectionLease $lease): PostgresLink
    {
        $transportHandle = $lease->transportHandle();

        if ($transportHandle instanceof PostgresLink) {
            return $transportHandle;
        }

        return $this->pool;
    }

    private function materializeResult(PostgresResult $result): ExecutionResult
    {
        $rows = [];

        while (($row = $result->fetchRow()) !== null) {
            $rows[] = $row;
        }

        $this->drainNextResults($result);

        $rowCount = $result->getRowCount();
        $affectedRows = $rowCount ?? count($rows);
        $columnCount = $result->getColumnCount() ?? 0;

        return new ExecutionResult(
            rows: $rows,
            affectedRows: $affectedRows,
            rowCountOverride: $rowCount,
            columnCount: $columnCount,
        );
    }

    private function drainNextResults(PostgresResult $result): void
    {
        $nextResult = $result->getNextResult();

        while ($nextResult !== null) {
            while ($nextResult->fetchRow() !== null) {
            }

            $nextResult = $nextResult->getNextResult();
        }
    }
}
