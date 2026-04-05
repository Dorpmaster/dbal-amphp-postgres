<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDbalConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function getenv;
use function sprintf;
use function str_replace;
use function strtolower;

final class IntegrationConnectionFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function params(): array
    {
        return [
            'host' => getenv('DBAL_AMP_PG_HOST') ?: 'host.docker.internal',
            'port' => (int) (getenv('DBAL_AMP_PG_PORT') ?: 55432),
            'dbname' => getenv('DBAL_AMP_PG_DBNAME') ?: 'dbal_amp_pg_test',
            'user' => getenv('DBAL_AMP_PG_USER') ?: 'dbal_amp_pg_user',
            'password' => getenv('DBAL_AMP_PG_PASSWORD') ?: 'dbal_amp_pg_password',
            'application_name' => getenv('DBAL_AMP_PG_APPLICATION_NAME') ?: 'dbal-amphp-postgres-tests',
        ];
    }

    public static function createConnection(): AmpPgConnection
    {
        $connection = (new AmpPgDriver())->connect(self::params());

        return $connection instanceof AmpPgConnection
            ? $connection
            : throw new \RuntimeException('AmpPgDriver did not return AmpPgConnection.');
    }

    public static function createDbalConnection(): Connection
    {
        return DriverManager::getConnection([
            ...self::params(),
            'driverClass' => AmpPgDriver::class,
            'wrapperClass' => AmpPgDbalConnection::class,
        ]);
    }

    public static function close(AmpPgConnection $connection): void
    {
        $connection->close();
    }

    public static function closeDbalConnection(Connection $connection): void
    {
        $connection->close();
    }

    public static function uniqueTableName(string $suffix): string
    {
        return sprintf(
            'it_%s',
            str_replace('-', '_', strtolower($suffix)),
        );
    }
}
