<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport;

use Amp\Postgres\PostgresArray;
use Amp\Postgres\PostgresByteA;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\UnsupportedParameterType;
use Doctrine\DBAL\ParameterType;

use function array_is_list;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_resource;
use function is_string;
use function stream_get_contents;

/** @internal */
final class ParameterConverter
{
    public function convertValue(mixed $value, ParameterType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ParameterType::NULL => null,
            ParameterType::BOOLEAN => $this->convertBoolean($value),
            ParameterType::INTEGER => $this->convertInteger($value),
            ParameterType::ASCII => $this->convertStringLike($value),
            ParameterType::STRING => $this->convertStringParameter($value),
            ParameterType::BINARY, ParameterType::LARGE_OBJECT => is_resource($value)
                ? new PostgresByteA((string) stream_get_contents($value))
                : $this->convertBinaryLike($value),
        };
    }

    /**
     * @param array<int|string, mixed> $values
     * @param array<int|string, ParameterType> $types
     *
     * @return array<int|string, mixed>
     */
    public function convertParameters(array $values, array $types): array
    {
        $converted = [];

        foreach ($values as $parameter => $value) {
            $converted[$parameter] = $this->convertValue($value, $types[$parameter] ?? ParameterType::STRING);
        }

        return $converted;
    }

    private function convertBoolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new UnsupportedParameterType('Boolean parameter expects bool, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    private function convertInteger(mixed $value): int
    {
        if (! is_int($value)) {
            throw new UnsupportedParameterType('Integer parameter expects int, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    private function convertStringLike(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnsupportedParameterType('String parameter expects string, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    private function convertStringParameter(mixed $value): bool|int|float|string|PostgresArray
    {
        if (is_array($value)) {
            return $this->convertPostgresArray($value);
        }

        return $this->convertScalarPassThrough($value);
    }

    private function convertBinaryLike(mixed $value): PostgresByteA
    {
        if (! is_string($value)) {
            throw new UnsupportedParameterType('Binary parameter expects string, got ' . get_debug_type($value) . '.');
        }

        return new PostgresByteA($value);
    }

    private function convertScalarPassThrough(mixed $value): bool|int|float|string
    {
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new UnsupportedParameterType('Unsupported parameter value type: ' . get_debug_type($value) . '.');
    }

    /**
     * @param array<mixed> $value
     */
    private function convertPostgresArray(array $value): PostgresArray
    {
        if (! array_is_list($value)) {
            throw new UnsupportedParameterType('Associative arrays are not supported as PostgreSQL array parameters.');
        }

        if ($value === []) {
            return new PostgresArray([]);
        }

        $elementType = null;

        foreach ($value as $element) {
            if (is_array($element)) {
                throw new UnsupportedParameterType(
                    'Multidimensional arrays are not supported as PostgreSQL array parameters.'
                );
            }

            if ($element === null) {
                throw new UnsupportedParameterType(
                    'Null elements are not supported in PostgreSQL array parameters.'
                );
            }

            $currentType = match (true) {
                is_string($element) => 'string',
                is_int($element) => 'int',
                is_bool($element) => 'bool',
                default => throw new UnsupportedParameterType(
                    'Unsupported PostgreSQL array element type: ' . get_debug_type($element) . '.'
                ),
            };

            if ($elementType !== null && $elementType !== $currentType) {
                throw new UnsupportedParameterType(
                    'Mixed scalar arrays are not supported as PostgreSQL array parameters.'
                );
            }

            $elementType = $currentType;
        }

        return new PostgresArray($value);
    }
}
