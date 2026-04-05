<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Closure;
use Doctrine\DBAL\Driver\Result as DriverResult;

/** @internal */
final class AmpPgCommandResult implements DriverResult
{
    private bool $completed = false;

    public function __construct(
        private readonly int|string $affectedRows,
        private ?Closure $onComplete = null,
    ) {
    }

    public function fetchNumeric(): array|false
    {
        $this->complete();

        return false;
    }

    public function fetchAssociative(): array|false
    {
        $this->complete();

        return false;
    }

    public function fetchOne(): mixed
    {
        $this->complete();

        return false;
    }

    public function fetchAllNumeric(): array
    {
        $this->complete();

        return [];
    }

    public function fetchAllAssociative(): array
    {
        $this->complete();

        return [];
    }

    public function fetchFirstColumn(): array
    {
        $this->complete();

        return [];
    }

    public function rowCount(): int|string
    {
        $this->complete();

        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        $this->complete();

        return 0;
    }

    public function free(): void
    {
        $this->complete();
    }

    private function complete(): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;

        if ($this->onComplete !== null) {
            ($this->onComplete)();
            $this->onComplete = null;
        }
    }
}
