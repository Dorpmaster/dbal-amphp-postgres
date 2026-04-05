<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Driver;

use Doctrine\DBAL\Driver\AbstractException;

/** @internal */
final class NotImplementedDriverException extends AbstractException
{
    public static function forOperation(string $operation): self
    {
        return new self($operation . ': Not implemented yet');
    }
}
