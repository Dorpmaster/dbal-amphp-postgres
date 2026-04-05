<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport;

use function implode;
use function is_array;
use function is_scalar;
use function ksort;
use function sprintf;
use function str_replace;

/** @internal */
final class DsnBuilder
{
    /**
     * @param array<string, mixed> $params
     */
    public static function build(array $params): string
    {
        $components = [];

        self::appendComponent($components, 'host', $params['host'] ?? null);
        self::appendComponent($components, 'port', $params['port'] ?? null);
        self::appendComponent($components, 'dbname', $params['dbname'] ?? null);
        self::appendComponent($components, 'user', $params['user'] ?? null);
        self::appendComponent($components, 'password', $params['password'] ?? null);
        self::appendComponent($components, 'application_name', $params['application_name'] ?? null);
        self::appendComponent($components, 'sslmode', $params['sslmode'] ?? null);
        self::appendComponent($components, 'options', self::normalizeOptions($params['options'] ?? null));

        return implode(' ', $components);
    }

    /**
     * @param list<string> $components
     */
    private static function appendComponent(array &$components, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $components[] = sprintf("%s='%s'", $key, self::escape((string) $value));
    }

    private static function normalizeOptions(mixed $options): ?string
    {
        if ($options === null || $options === '') {
            return null;
        }

        if (! is_array($options)) {
            return (string) $options;
        }

        ksort($options);

        $pairs = [];
        foreach ($options as $key => $value) {
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            $pairs[] = $key . '=' . $value;
        }

        return $pairs === [] ? null : implode(' ', $pairs);
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '\''], ['\\\\', '\\\''], $value);
    }
}
