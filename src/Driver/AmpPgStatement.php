<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Dorpmaster\DbalAmpPostgres\Transport\ParameterConverter;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use LogicException;

use function array_values;
use function is_int;
use function ksort;

final class AmpPgStatement implements DriverStatement
{
    /** @var array<int|string, mixed> */
    private array $boundValues = [];

    /** @var array<int|string, mixed> */
    private array $boundReferences = [];

    /** @var array<int|string, ParameterType> */
    private array $parameterTypes = [];

    public function __construct(
        private readonly AmpPgConnection $connection,
        private readonly string $sql,
        private readonly ParameterConverter $parameterConverter,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->boundValues[$param] = $value;
        $this->parameterTypes[$param] = $type;
    }

    public function bindParam(int|string $param, mixed &$variable, ParameterType $type = ParameterType::STRING): void
    {
        $this->boundReferences[$param] = &$variable;
        $this->parameterTypes[$param] = $type;
    }

    public function execute(): DriverResult
    {
        $context = $this->executionContext();

        return $this->connection->executePreparedStatement($context['sql'], $context['parameters']);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function boundValues(): array
    {
        return $this->boundValues;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function resolvedParameters(): array
    {
        $parameters = $this->boundValues;

        foreach ($this->boundReferences as $name => &$value) {
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * @return array{
     *     sql: string,
     *     parameters: array<int|string, mixed>,
     *     parameterTypes: array<int|string, ParameterType>
     * }
     */
    private function executionContext(): array
    {
        return [
            'sql' => $this->sql,
            'parameters' => $this->normalizeExecutionParameters(
                $this->parameterConverter->convertParameters(
                    $this->resolvedParameters(),
                    $this->parameterTypes,
                ),
            ),
            'parameterTypes' => $this->parameterTypes,
        ];
    }

    /**
     * @param array<int|string, mixed> $parameters
     *
     * @return array<int|string, mixed>
     */
    private function normalizeExecutionParameters(array $parameters): array
    {
        if ($parameters === []) {
            return [];
        }

        $hasIntegerKeys = false;
        $hasStringKeys = false;

        foreach ($parameters as $parameter => $_value) {
            if (is_int($parameter)) {
                $hasIntegerKeys = true;

                continue;
            }

            $hasStringKeys = true;
        }

        if ($hasIntegerKeys && $hasStringKeys) {
            throw new LogicException('Mixing positional and named parameters is not supported.');
        }

        if (! $hasIntegerKeys) {
            return $parameters;
        }

        ksort($parameters);

        return array_values($parameters);
    }
}
