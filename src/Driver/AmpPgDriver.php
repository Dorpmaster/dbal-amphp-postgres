<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Dorpmaster\DbalAmpPostgres\Observability\DriverObserver;
use Dorpmaster\DbalAmpPostgres\Observability\NullDriverObserver;
use Dorpmaster\DbalAmpPostgres\Transport\ConnectionPoolFactory;
use Dorpmaster\DbalAmpPostgres\Transport\Execution\AmpPgExecutionExecutor;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use InvalidArgumentException;
use SensitiveParameter;

final class AmpPgDriver extends AbstractPostgreSQLDriver
{
    private readonly ConnectionPoolFactory $connectionPoolFactory;

    public function __construct(?ConnectionPoolFactory $connectionPoolFactory = null)
    {
        $this->connectionPoolFactory = $connectionPoolFactory ?? new ConnectionPoolFactory();
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        $observer = $this->resolveObserver($params);
        $poolConfiguration = $this->connectionPoolFactory->createConfiguration($params);
        $executionExecutor = new AmpPgExecutionExecutor($this->connectionPoolFactory->createPool($params));

        return new AmpPgConnection(
            params: $params,
            poolConfiguration: $poolConfiguration,
            executionExecutor: $executionExecutor,
            observer: $observer,
        );
    }

    public function getExceptionConverter(): AmpPgExceptionConverter
    {
        return new AmpPgExceptionConverter();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveObserver(array $params): DriverObserver
    {
        $observer = $params['dbal_amp_pg_observer'] ?? null;

        if ($observer === null) {
            return new NullDriverObserver();
        }

        if (! $observer instanceof DriverObserver) {
            throw new InvalidArgumentException(
                'Connection parameter "dbal_amp_pg_observer" must implement DriverObserver.'
            );
        }

        return $observer;
    }
}
