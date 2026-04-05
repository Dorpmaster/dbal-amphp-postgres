<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Support;

use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionResult;
use RuntimeException;
use Throwable;

use function array_shift;
use function count;

final class FakeExecutionExecutor implements ExecutionExecutor
{
    /** @var list<object|Throwable> */
    private array $transactionQueue = [];

    /** @var list<ExecutionResult|Throwable> */
    private array $queryQueue = [];

    /** @var list<ExecutionResult|Throwable> */
    private array $statementQueue = [];

    /** @var list<array{method: string, sql: string, params: array<int|string, mixed>, leaseId: string}> */
    private array $calls = [];

    private bool $shutdown = false;

    public function queueQueryResult(ExecutionResult $result): void
    {
        $this->queryQueue[] = $result;
    }

    public function queueTransactionHandle(?object $handle = null): object
    {
        $handle ??= new class () {
        };

        $this->transactionQueue[] = $handle;

        return $handle;
    }

    public function queueTransactionException(Throwable $throwable): void
    {
        $this->transactionQueue[] = $throwable;
    }

    public function queueQueryException(Throwable $throwable): void
    {
        $this->queryQueue[] = $throwable;
    }

    public function queueStatementResult(ExecutionResult $result): void
    {
        $this->statementQueue[] = $result;
    }

    public function queueStatementException(Throwable $throwable): void
    {
        $this->statementQueue[] = $throwable;
    }

    public function executeQuery(string $sql, ConnectionLease $lease): ExecutionResult
    {
        $this->calls[] = [
            'method' => 'query',
            'sql' => $sql,
            'params' => [],
            'leaseId' => $lease->id(),
        ];

        return $this->dequeue('query');
    }

    public function executeCommand(string $sql, ConnectionLease $lease): ExecutionResult
    {
        $this->calls[] = [
            'method' => 'command',
            'sql' => $sql,
            'params' => [],
            'leaseId' => $lease->id(),
        ];

        return $this->dequeue('query');
    }

    public function executeStatement(string $sql, array $params, ConnectionLease $lease): ExecutionResult
    {
        $this->calls[] = [
            'method' => 'statement',
            'sql' => $sql,
            'params' => $params,
            'leaseId' => $lease->id(),
        ];

        return $this->dequeue('statement');
    }

    public function beginTransaction(ConnectionLease $lease): object
    {
        $this->calls[] = [
            'method' => 'begin-transaction',
            'sql' => '',
            'params' => [],
            'leaseId' => $lease->id(),
        ];

        return $this->dequeueTransaction();
    }

    public function beginNestedTransaction(object $transactionHandle): object
    {
        $this->calls[] = [
            'method' => 'begin-nested-transaction',
            'sql' => '',
            'params' => [],
            'leaseId' => '',
        ];

        return $this->dequeueTransaction();
    }

    public function commitTransaction(object $transactionHandle): void
    {
        $this->calls[] = [
            'method' => 'commit-transaction',
            'sql' => '',
            'params' => [],
            'leaseId' => '',
        ];
    }

    public function rollbackTransaction(object $transactionHandle): void
    {
        $this->calls[] = [
            'method' => 'rollback-transaction',
            'sql' => '',
            'params' => [],
            'leaseId' => '',
        ];
    }

    public function fetchServerVersion(?ConnectionLease $lease = null): string
    {
        $this->calls[] = [
            'method' => 'server-version',
            'sql' => '',
            'params' => [],
            'leaseId' => $lease?->id() ?? '',
        ];

        return '17.2';
    }

    public function getNativeConnection(?ConnectionLease $lease = null): object
    {
        return $lease?->transportHandle() ?? new class () {
        };
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
    }

    /**
     * @return list<array{method: string, sql: string, params: array<int|string, mixed>, leaseId: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    private function dequeue(string $method): ExecutionResult
    {
        if ($method === 'query') {
            $next = array_shift($this->queryQueue);
        } else {
            $next = array_shift($this->statementQueue);
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof ExecutionResult) {
            return $next;
        }

        throw new RuntimeException('No fake execution scenario configured for method "' . $method . '".');
    }

    private function dequeueTransaction(): object
    {
        $next = array_shift($this->transactionQueue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next !== null) {
            return $next;
        }

        return new class () {
        };
    }
}
