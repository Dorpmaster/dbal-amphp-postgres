<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Doctrine\DBAL\Driver\AbstractException;
use Throwable;

final class AmpPgDriverException extends AbstractException
{
    public static function fromThrowable(Throwable $throwable, ?string $sqlState = null): self
    {
        return new self(
            message: $throwable->getMessage(),
            sqlState: $sqlState,
            previous: $throwable,
        );
    }
}
