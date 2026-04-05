<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use SensitiveParameter;

/** @internal */
final class ConnectionPoolFactory
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function createConfiguration(
        #[SensitiveParameter]
        array $params,
    ): array {
        return [
            'dsn' => DsnBuilder::build($params),
            'max_connections' => $params['max_connections'] ?? 10,
            'idle_timeout' => $params['idle_timeout'] ?? PostgresConnectionPool::DEFAULT_IDLE_TIMEOUT,
            'reset_connections' => $params['reset_connections'] ?? true,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createConfig(
        #[SensitiveParameter]
        array $params,
    ): PostgresConfig {
        return new PostgresConfig(
            host: (string) ($params['host'] ?? '127.0.0.1'),
            port: (int) ($params['port'] ?? PostgresConfig::DEFAULT_PORT),
            user: isset($params['user']) ? (string) $params['user'] : null,
            password: isset($params['password']) ? (string) $params['password'] : null,
            database: isset($params['dbname']) ? (string) $params['dbname'] : null,
            applicationName: isset($params['application_name']) ? (string) $params['application_name'] : null,
            sslMode: isset($params['sslmode']) ? (string) $params['sslmode'] : null,
            options: self::normalizeOptions($params['options'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createPool(
        #[SensitiveParameter]
        array $params,
    ): PostgresConnectionPool {
        $configuration = $this->createConfiguration($params);

        return new PostgresConnectionPool(
            config: $this->createConfig($params),
            maxConnections: (int) $configuration['max_connections'],
            idleTimeout: (int) $configuration['idle_timeout'],
            resetConnections: (bool) $configuration['reset_connections'],
        );
    }

    private static function normalizeOptions(mixed $options): ?string
    {
        if ($options === null || $options === '') {
            return null;
        }

        if (! is_array($options)) {
            return (string) $options;
        }

        $pairs = [];
        ksort($options);

        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = $key . '=' . $value;
        }

        return $pairs === [] ? null : implode(' ', $pairs);
    }
}
