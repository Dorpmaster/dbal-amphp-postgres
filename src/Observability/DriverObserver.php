<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Observability;

interface DriverObserver
{
    /**
     * @param array<string, mixed> $context
     */
    public function onEvent(string $event, array $context = []): void;
}
