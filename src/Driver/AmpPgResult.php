<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Closure;
use Doctrine\DBAL\Driver\FetchUtils;
use Doctrine\DBAL\Driver\Result as DriverResult;

use function array_is_list;
use function array_values;
use function count;
use function is_string;

final class AmpPgResult implements DriverResult
{
    /** @var list<list<mixed>> */
    private array $numericRows = [];

    /** @var list<array<string, mixed>> */
    private array $associativeRows = [];

    private int $cursor = 0;

    private bool $freed = false;

    private ?Closure $onFree;

    /**
     * @param list<array<int|string, mixed>> $rows
     * @param int|numeric-string|null $rowCountOverride
     */
    public function __construct(
        array $rows,
        private readonly int|string|null $rowCountOverride = null,
        ?Closure $onFree = null,
    ) {
        $this->onFree = $onFree;

        foreach ($rows as $row) {
            [$numericRow, $associativeRow] = $this->normalizeRow($row);

            $this->numericRows[] = $numericRow;
            $this->associativeRows[] = $associativeRow;
        }
    }

    public function fetchNumeric(): array|false
    {
        $row = $this->nextNumericRow();
        if ($row === false) {
            return false;
        }

        return $row;
    }

    public function fetchAssociative(): array|false
    {
        $row = $this->nextAssociativeRow();
        if ($row === false) {
            return false;
        }

        return $row;
    }

    public function fetchOne(): mixed
    {
        return FetchUtils::fetchOne($this);
    }

    public function fetchAllNumeric(): array
    {
        return FetchUtils::fetchAllNumeric($this);
    }

    public function fetchAllAssociative(): array
    {
        return FetchUtils::fetchAllAssociative($this);
    }

    public function fetchFirstColumn(): array
    {
        return FetchUtils::fetchFirstColumn($this);
    }

    public function rowCount(): int|string
    {
        if ($this->freed) {
            return 0;
        }

        return $this->rowCountOverride ?? count($this->numericRows);
    }

    public function columnCount(): int
    {
        if ($this->freed || $this->numericRows === []) {
            return 0;
        }

        return count($this->numericRows[0]);
    }

    public function free(): void
    {
        if ($this->freed) {
            return;
        }

        $this->freed = true;
        $this->numericRows = [];
        $this->associativeRows = [];
        $this->cursor = 0;

        if ($this->onFree !== null) {
            ($this->onFree)();
            $this->onFree = null;
        }
    }

    /**
     * @param array<int|string, mixed> $row
     *
     * @return array{0: list<mixed>, 1: array<string, mixed>}
     */
    private function normalizeRow(array $row): array
    {
        $stringKeyedRow = [];
        $integerValues = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $stringKeyedRow[$key] = $value;

                continue;
            }

            $integerValues[] = $value;
        }

        if ($stringKeyedRow !== []) {
            return [array_values($stringKeyedRow), $stringKeyedRow];
        }

        if (array_is_list($row)) {
            $numericRow = $row;
        } else {
            $numericRow = $integerValues;
        }

        $associativeRow = [];
        foreach ($numericRow as $index => $value) {
            $associativeRow[(string) $index] = $value;
        }

        return [$numericRow, $associativeRow];
    }

    /**
     * @return list<mixed>|false
     */
    private function nextNumericRow(): array|false
    {
        if ($this->freed || ! isset($this->numericRows[$this->cursor])) {
            return false;
        }

        return $this->numericRows[$this->cursor++];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function nextAssociativeRow(): array|false
    {
        if ($this->freed || ! isset($this->associativeRows[$this->cursor])) {
            return false;
        }

        return $this->associativeRows[$this->cursor++];
    }

    public function __destruct()
    {
        $this->free();
    }
}
