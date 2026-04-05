<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Smoke;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testAutoloadWorks(): void
    {
        self::assertTrue(class_exists(AmpPgDriver::class));
    }
}
