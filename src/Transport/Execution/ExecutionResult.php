<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Transport\Execution;

use function count;

/** @internal */
final class ExecutionResult
{
    /**
     * @param list<array<int|string, mixed>> $rows
     * @param int|numeric-string|null $affectedRows
     * @param int|numeric-string|null $rowCountOverride
     */
    public function __construct(
        private array $rows = [],
        private int|string|null $affectedRows = null,
        private int|string|null $rowCountOverride = null,
        private int $columnCount = 0,
    ) {
        if ($this->columnCount === 0 && $this->rows !== []) {
            $this->columnCount = count($this->rows[0]);
        }
    }

    /**
     * @return list<array<int|string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return int|numeric-string|null
     */
    public function affectedRows(): int|string|null
    {
        return $this->affectedRows;
    }

    /**
     * @return int|numeric-string|null
     */
    public function rowCountOverride(): int|string|null
    {
        return $this->rowCountOverride;
    }

    public function columnCount(): int
    {
        return $this->columnCount;
    }

    /**
     * @return int|numeric-string
     */
    public function effectiveAffectedRows(): int|string
    {
        return $this->affectedRows ?? $this->rowCountOverride ?? count($this->rows);
    }
}
