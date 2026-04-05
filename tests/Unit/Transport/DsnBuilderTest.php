<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Transport;

use Dorpmaster\DbalAmpPostgres\Transport\DsnBuilder;
use PHPUnit\Framework\TestCase;

final class DsnBuilderTest extends TestCase
{
    public function testBuildsMinimalDsn(): void
    {
        self::assertSame('', DsnBuilder::build([]));
    }

    public function testBuildsDsnWithConnectionCoordinates(): void
    {
        self::assertSame(
            "host='db.internal' port='5432' dbname='service_db' user='app' password='secret'",
            DsnBuilder::build([
                'host' => 'db.internal',
                'port' => 5432,
                'dbname' => 'service_db',
                'user' => 'app',
                'password' => 'secret',
            ]),
        );
    }

    public function testBuildsDsnWithApplicationName(): void
    {
        self::assertSame(
            "host='db.internal' application_name='dbal-driver'",
            DsnBuilder::build([
                'host' => 'db.internal',
                'application_name' => 'dbal-driver',
            ]),
        );
    }

    public function testBuildsDsnWithSslmode(): void
    {
        self::assertSame(
            "host='db.internal' sslmode='require'",
            DsnBuilder::build([
                'host' => 'db.internal',
                'sslmode' => 'require',
            ]),
        );
    }

    public function testBuildsDeterministicOutputForOptions(): void
    {
        self::assertSame(
            "host='db.internal' options='application_name=dbal connect_timeout=5'",
            DsnBuilder::build([
                'host' => 'db.internal',
                'options' => [
                    'connect_timeout' => 5,
                    'application_name' => 'dbal',
                ],
            ]),
        );
    }
}
