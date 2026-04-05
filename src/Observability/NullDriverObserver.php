<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Observability;

final class NullDriverObserver implements DriverObserver
{
    public function onEvent(string $event, array $context = []): void
    {
    }
}
