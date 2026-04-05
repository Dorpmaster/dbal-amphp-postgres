<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Support;

use Dorpmaster\DbalAmpPostgres\Observability\DriverObserver;

final class RecordingObserver implements DriverObserver
{
    /** @var list<array{event: string, context: array<string, mixed>}> */
    private array $events = [];

    public function onEvent(string $event, array $context = []): void
    {
        $this->events[] = [
            'event' => $event,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{event: string, context: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
