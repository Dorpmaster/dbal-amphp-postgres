<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport\Execution;

use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;

/** @internal */
interface ExecutionExecutor
{
    public function executeQuery(string $sql, ConnectionLease $lease): ExecutionResult;

    public function executeCommand(string $sql, ConnectionLease $lease): ExecutionResult;

    /**
     * @param array<int|string, mixed> $params
     */
    public function executeStatement(string $sql, array $params, ConnectionLease $lease): ExecutionResult;

    public function beginTransaction(ConnectionLease $lease): object;

    public function beginNestedTransaction(object $transactionHandle): object;

    public function commitTransaction(object $transactionHandle): void;

    public function rollbackTransaction(object $transactionHandle): void;

    public function fetchServerVersion(?ConnectionLease $lease = null): string;

    public function getNativeConnection(?ConnectionLease $lease = null): object;

    public function shutdown(): void;
}
