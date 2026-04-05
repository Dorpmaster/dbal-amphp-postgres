<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport\Execution;

use Dorpmaster\DbalAmpPostgres\Driver\NotImplementedDriverException;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;

/** @internal */
final class NotImplementedExecutionExecutor implements ExecutionExecutor
{
    public function executeQuery(string $sql, ConnectionLease $lease): ExecutionResult
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::executeQuery');
    }

    public function executeCommand(string $sql, ConnectionLease $lease): ExecutionResult
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::executeCommand');
    }

    public function executeStatement(string $sql, array $params, ConnectionLease $lease): ExecutionResult
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::executeStatement');
    }

    public function beginTransaction(ConnectionLease $lease): object
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::beginTransaction');
    }

    public function beginNestedTransaction(object $transactionHandle): object
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::beginNestedTransaction');
    }

    public function commitTransaction(object $transactionHandle): void
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::commitTransaction');
    }

    public function rollbackTransaction(object $transactionHandle): void
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::rollbackTransaction');
    }

    public function fetchServerVersion(?ConnectionLease $lease = null): string
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::fetchServerVersion');
    }

    public function getNativeConnection(?ConnectionLease $lease = null): object
    {
        throw NotImplementedDriverException::forOperation('ExecutionExecutor::getNativeConnection');
    }

    public function shutdown(): void
    {
    }
}
