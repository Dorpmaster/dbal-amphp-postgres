<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Amp\Closable;
use Amp\Postgres\PostgresQueryError;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Closure;
use Dorpmaster\DbalAmpPostgres\Observability\DriverObserver;
use Dorpmaster\DbalAmpPostgres\Observability\NullDriverObserver;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLease;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionLeaseManager;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionPoolFactory;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\AmpPgExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\ExecutionResult;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\NotImplementedExecutionExecutor;
use Dorpmaster\DbalAmpPostgres\Transport\ParameterConverter;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use RuntimeException;
use Throwable;

use function array_key_last;
use function array_reverse;
use function count;
use function is_int;
use function is_string;
use function str_starts_with;

final class AmpPgConnection implements DriverConnection, Closable
{
    /** @var list<object> */
    private array $transactionHandleStack = [];

    /** @var list<Closure(): void> */
    private array $onCloseCallbacks = [];

    private readonly ConnectionLeaseManager $leaseManager;

    private readonly ParameterConverter $parameterConverter;

    private readonly ExecutionExecutor $executionExecutor;

    private readonly DriverObserver $observer;

    private readonly bool $ownsExecutionExecutor;

    private bool $closed = false;

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $poolConfiguration
     */
    public function __construct(
        private readonly array $params,
        private readonly array $poolConfiguration = [],
        ?ConnectionLeaseManager $leaseManager = null,
        ?ParameterConverter $parameterConverter = null,
        ?ExecutionExecutor $executionExecutor = null,
        ?DriverObserver $observer = null,
    ) {
        $this->leaseManager = $leaseManager ?? new ConnectionLeaseManager();
        $this->parameterConverter = $parameterConverter ?? new ParameterConverter();
        $this->executionExecutor = $executionExecutor ?? $this->createDefaultExecutor($params);
        $this->observer = $observer ?? new NullDriverObserver();
        $this->ownsExecutionExecutor = $executionExecutor === null;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable) {
        }
    }

    public function prepare(string $sql): DriverStatement
    {
        $this->assertOpen();

        return new AmpPgStatement($this, $sql, $this->parameterConverter);
    }

    public function query(string $sql): DriverResult
    {
        return $this->executeQuerySql($sql);
    }

    public function quote(string $value): string
    {
        $this->assertOpen();

        throw NotImplementedDriverException::forOperation('AmpPgConnection::quote');
    }

    public function exec(string $sql): int|string
    {
        return $this->executeExecSql($sql);
    }

    public function lastInsertId(): int|string
    {
        $this->assertOpen();

        $lease = $this->currentTransactionLease();

        if ($lease === null) {
            throw AmpPgDriverException::fromThrowable(
                new RuntimeException(
                    'AmpPgConnection::lastInsertId requires an active transaction because pooled PostgreSQL '
                    . 'connections do not provide a safe session-wide identity context outside a pinned lease.'
                ),
                '55000',
            );
        }

        try {
            $executionResult = $this->executionExecutor->executeQuery(
                'SELECT LASTVAL() AS last_insert_id',
                $lease,
            );
        } catch (Throwable $throwable) {
            throw $this->asDriverException($throwable);
        }

        $lastInsertId = $executionResult->rows()[0]['last_insert_id'] ?? null;

        if (! is_int($lastInsertId) && ! is_string($lastInsertId)) {
            throw AmpPgDriverException::fromThrowable(
                new RuntimeException(
                    'AmpPgConnection::lastInsertId did not receive a valid PostgreSQL sequence value.'
                ),
                '55000',
            );
        }

        return $lastInsertId;
    }

    public function beginTransaction(): void
    {
        $this->assertOpen();

        $wasActive = $this->leaseManager->transactionScope()->isActive();
        $lease = $this->leaseManager->beginTransaction();

        try {
            if ($wasActive) {
                $parentTransaction = $this->currentTransactionHandle();
                $transactionHandle = $this->executionExecutor->beginNestedTransaction($parentTransaction);
            } else {
                $transactionHandle = $this->executionExecutor->beginTransaction($lease);
            }
        } catch (Throwable $throwable) {
            $this->leaseManager->rollbackTransaction();

            throw $throwable;
        }

        $this->transactionHandleStack[] = $transactionHandle;
        $lease->attachTransportHandle($transactionHandle);
        $this->observe('transaction.began', [
            'depth' => $this->leaseManager->transactionScope()->depth(),
            'lease_id' => $lease->id(),
        ]);
    }

    public function commit(): void
    {
        $this->assertOpen();

        $lease = $this->requireTransactionLease();
        $currentTransaction = $this->currentTransactionHandle();

        try {
            $this->executionExecutor->commitTransaction($currentTransaction);
        } catch (Throwable $throwable) {
            $this->handleTransactionFailure($lease, $throwable);
        }

        array_pop($this->transactionHandleStack);
        $this->rebindTransactionHandle($lease);
        $this->leaseManager->commitTransaction();
        $this->observe('transaction.committed', [
            'depth' => $this->leaseManager->transactionScope()->depth(),
            'lease_id' => $lease->id(),
        ]);
    }

    public function rollBack(): void
    {
        $this->assertOpen();

        $lease = $this->requireTransactionLease();
        $currentTransaction = $this->currentTransactionHandle();

        try {
            $this->executionExecutor->rollbackTransaction($currentTransaction);
        } catch (Throwable $throwable) {
            $this->handleTransactionFailure($lease, $throwable);
        }

        array_pop($this->transactionHandleStack);
        $this->rebindTransactionHandle($lease);
        $this->leaseManager->rollbackTransaction();
        $this->observe('transaction.rolled_back', [
            'depth' => $this->leaseManager->transactionScope()->depth(),
            'lease_id' => $lease->id(),
        ]);
    }

    public function getServerVersion(): string
    {
        $this->assertOpen();

        return $this->executionExecutor->fetchServerVersion($this->currentTransactionLease());
    }

    public function getNativeConnection(): object
    {
        $this->assertOpen();

        return $this->executionExecutor->getNativeConnection($this->currentTransactionLease());
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $hadActiveTransaction = $this->leaseManager->transactionScope()->isActive();
        $activeLeaseCount = $this->leaseManager->activeLeaseCount();

        foreach (array_reverse($this->transactionHandleStack) as $handle) {
            $this->closeHandle($handle);
        }

        $this->transactionHandleStack = [];
        $this->leaseManager->close();

        if ($this->ownsExecutionExecutor) {
            $this->executionExecutor->shutdown();
            $this->observe('pool.shutdown', []);
        }

        $this->closed = true;
        $this->observe('connection.closed', [
            'had_active_transaction' => $hadActiveTransaction,
            'active_leases' => $activeLeaseCount,
            'owned_executor' => $this->ownsExecutionExecutor,
        ]);
        $this->notifyOnClose();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();

            return;
        }

        $this->onCloseCallbacks[] = $onClose;
    }

    public function leaseManager(): ConnectionLeaseManager
    {
        return $this->leaseManager;
    }

    public function acquireLeaseForOperation(): ConnectionLease
    {
        return $this->leaseManager->acquireOperationLease();
    }

    public function currentTransactionLease(): ?ConnectionLease
    {
        return $this->leaseManager->currentTransactionLease();
    }

    public function releaseOperationLease(ConnectionLease $lease): void
    {
        $this->leaseManager->releaseOperationLease($lease);
    }

    public function pinResultLease(ConnectionLease $lease): void
    {
        $this->leaseManager->pinLeaseForResult($lease);
    }

    public function markLeaseBroken(ConnectionLease $lease): void
    {
        $this->leaseManager->markLeaseBroken($lease);
    }

    /**
     * @param list<array<int|string, mixed>> $rows
     * @param int|numeric-string|null $rowCountOverride
     */
    public function createResult(
        array $rows,
        ConnectionLease $lease,
        int|string|null $rowCountOverride = null,
    ): AmpPgResult {
        $this->pinResultLease($lease);

        return new AmpPgResult(
            rows: $rows,
            rowCountOverride: $rowCountOverride,
            onFree: function () use ($lease): void {
                $this->leaseManager->releaseResultLease($lease);
            },
        );
    }

    public function executeQuerySql(string $sql): AmpPgResult
    {
        $this->assertOpen();

        $lease = $this->acquireLeaseForOperation();
        $this->observeExecutionStarted('query', $sql, 0);

        try {
            $executionResult = $this->executionExecutor->executeQuery($sql, $lease);
            $this->observeExecutionFinished('query', $sql, 0, $executionResult);

            return $this->createResultFromExecutionResult($executionResult, $lease);
        } catch (Throwable $throwable) {
            $this->handleExecutionFailure($lease, $throwable, $sql, 0, 'query');
        }
    }

    /**
     * @return int|numeric-string
     */
    public function executeExecSql(string $sql): int|string
    {
        $this->assertOpen();

        $lease = $this->acquireLeaseForOperation();
        $this->observeExecutionStarted('exec', $sql, 0);

        try {
            $executionResult = $this->executionExecutor->executeCommand($sql, $lease);
            $affectedRows = $executionResult->effectiveAffectedRows();
            $this->releaseOperationLease($lease);
            $this->observeExecutionFinished('exec', $sql, 0, $executionResult);

            return $affectedRows;
        } catch (Throwable $throwable) {
            $this->handleExecutionFailure($lease, $throwable, $sql, 0, 'exec');
        }
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function executePreparedStatement(string $sql, array $params): DriverResult
    {
        $this->assertOpen();

        $lease = $this->acquireLeaseForOperation();
        $parameterCount = count($params);
        $this->observeExecutionStarted('statement', $sql, $parameterCount);

        try {
            $executionResult = $this->executionExecutor->executeStatement($sql, $params, $lease);
            $this->observeExecutionFinished('statement', $sql, $parameterCount, $executionResult);

            if ($executionResult->columnCount() === 0) {
                return $this->createCommandResult($executionResult, $lease);
            }

            return $this->createResultFromExecutionResult($executionResult, $lease);
        } catch (Throwable $throwable) {
            $this->handleExecutionFailure($lease, $throwable, $sql, $parameterCount, 'statement');
        }
    }

    public function createResultFromExecutionResult(
        ExecutionResult $executionResult,
        ConnectionLease $lease,
    ): AmpPgResult {
        return $this->createResult(
            rows: $executionResult->rows(),
            lease: $lease,
            rowCountOverride: $executionResult->rowCountOverride(),
        );
    }

    /**
     * @return never
     */
    public function handleExecutionFailure(
        ConnectionLease $lease,
        Throwable $throwable,
        string $sql,
        int $parameterCount,
        string $path,
    ): never {
        $fatalSessionError = $this->isFatalSessionError($throwable);
        $this->observeExecutionFailed($path, $sql, $parameterCount, $throwable, $fatalSessionError);

        if ($throwable instanceof SqlQueryError) {
            if (! $fatalSessionError && ! $this->isCurrentTransactionLease($lease)) {
                $this->releaseOperationLease($lease);
            }

            if (! $fatalSessionError) {
                throw $this->asDriverException($throwable);
            }
        }

        if ($fatalSessionError || $this->isCurrentTransactionLease($lease)) {
            $this->handleTransactionFailure($lease, $throwable);
        }

        $this->observeLeaseMarkedBroken($lease, $throwable);
        $this->markLeaseBroken($lease);

        throw $this->asDriverException($throwable);
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function poolConfiguration(): array
    {
        return $this->poolConfiguration;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createDefaultExecutor(array $params): ExecutionExecutor
    {
        if (! isset($params['host'])) {
            return new NotImplementedExecutionExecutor();
        }

        return new AmpPgExecutionExecutor((new ConnectionPoolFactory())->createPool($params));
    }

    private function createCommandResult(
        ExecutionResult $executionResult,
        ConnectionLease $lease,
    ): AmpPgCommandResult {
        return new AmpPgCommandResult(
            affectedRows: $executionResult->effectiveAffectedRows(),
            onComplete: function () use ($lease): void {
                if ($this->isCurrentTransactionLease($lease)) {
                    return;
                }

                $this->releaseOperationLease($lease);
            },
        );
    }

    private function requireTransactionLease(): ConnectionLease
    {
        $lease = $this->currentTransactionLease();

        if ($lease === null) {
            throw NotImplementedDriverException::forOperation('AmpPgConnection transaction boundary');
        }

        return $lease;
    }

    private function currentTransactionHandle(): object
    {
        $transactionHandle = $this->transactionHandleStack[array_key_last($this->transactionHandleStack)] ?? null;

        if ($transactionHandle === null) {
            throw NotImplementedDriverException::forOperation('AmpPgConnection transaction handle');
        }

        return $transactionHandle;
    }

    private function isCurrentTransactionLease(ConnectionLease $lease): bool
    {
        return $lease === $this->currentTransactionLease();
    }

    private function rebindTransactionHandle(ConnectionLease $lease): void
    {
        $currentHandleIndex = array_key_last($this->transactionHandleStack);
        $currentHandle = $currentHandleIndex === null
            ? null
            : $this->transactionHandleStack[$currentHandleIndex];

        if ($currentHandle === null) {
            $lease->detachTransportHandle();

            return;
        }

        $lease->attachTransportHandle($currentHandle);
    }

    /**
     * @return never
     */
    private function handleTransactionFailure(ConnectionLease $lease, Throwable $throwable): never
    {
        foreach ($this->transactionHandleStack as $transactionHandle) {
            $this->closeHandle($transactionHandle);
        }

        $this->transactionHandleStack = [];
        $lease->detachTransportHandle();
        $this->observeLeaseMarkedBroken($lease, $throwable);
        $this->markLeaseBroken($lease);
        $this->leaseManager->abortTransaction();

        throw $this->asDriverException($throwable);
    }

    private function closeHandle(object $handle): void
    {
        if ($handle instanceof Closable && ! $handle->isClosed()) {
            $handle->close();
        }
    }

    private function asDriverException(Throwable $throwable): AbstractException
    {
        if ($throwable instanceof AbstractException) {
            return $throwable;
        }

        $sqlState = $this->extractSqlState($throwable);

        return AmpPgDriverException::fromThrowable($throwable, $sqlState);
    }

    private function observeExecutionStarted(string $path, string $sql, int $parameterCount): void
    {
        $this->observe('query.started', [
            'path' => $path,
            'sql' => $sql,
            'parameter_count' => $parameterCount,
            'transaction_active' => $this->leaseManager->transactionScope()->isActive(),
        ]);
    }

    private function observeExecutionFinished(
        string $path,
        string $sql,
        int $parameterCount,
        ExecutionResult $executionResult,
    ): void {
        $this->observe('query.finished', [
            'path' => $path,
            'sql' => $sql,
            'parameter_count' => $parameterCount,
            'row_count' => $executionResult->rowCountOverride() ?? count($executionResult->rows()),
            'column_count' => $executionResult->columnCount(),
            'affected_rows' => $executionResult->affectedRows(),
        ]);
    }

    private function observeExecutionFailed(
        string $path,
        string $sql,
        int $parameterCount,
        Throwable $throwable,
        bool $fatalSessionError,
    ): void {
        $this->observe('query.failed', [
            'path' => $path,
            'sql' => $sql,
            'parameter_count' => $parameterCount,
            'exception_class' => $throwable::class,
            'sqlstate' => $this->extractSqlState($throwable),
            'fatal_session_error' => $fatalSessionError,
        ]);
    }

    private function observeLeaseMarkedBroken(ConnectionLease $lease, Throwable $throwable): void
    {
        $this->observe('lease.marked_broken', [
            'lease_id' => $lease->id(),
            'exception_class' => $throwable::class,
            'sqlstate' => $this->extractSqlState($throwable),
            'transaction_active' => $this->leaseManager->transactionScope()->isActive(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function observe(string $event, array $context): void
    {
        try {
            $this->observer->onEvent($event, $context);
        } catch (Throwable) {
        }
    }

    private function extractSqlState(Throwable $throwable): ?string
    {
        if ($throwable instanceof PostgresQueryError) {
            $sqlState = $throwable->getDiagnostics()['sqlstate'] ?? null;

            return is_string($sqlState) ? $sqlState : null;
        }

        return null;
    }

    private function isFatalSessionError(Throwable $throwable): bool
    {
        if ($throwable instanceof SqlConnectionException || $throwable instanceof SqlException) {
            return true;
        }

        if (! $throwable instanceof PostgresQueryError) {
            return false;
        }

        $sqlState = $this->extractSqlState($throwable);

        if ($sqlState === null) {
            return false;
        }

        return str_starts_with($sqlState, '08')
            || $sqlState === '57P01'
            || $sqlState === '57P02'
            || $sqlState === '57P03'
            || $sqlState === '58030';
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw AmpPgDriverException::fromThrowable(new RuntimeException('AmpPgConnection is closed.'));
        }
    }

    private function notifyOnClose(): void
    {
        foreach ($this->onCloseCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable) {
            }
        }

        $this->onCloseCallbacks = [];
    }
}
