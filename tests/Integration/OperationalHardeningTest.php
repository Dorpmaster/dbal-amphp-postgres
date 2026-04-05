<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration;

use Amp\Closable;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriverException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Throwable;

final class OperationalHardeningTest extends TestCase
{
    private ?AmpPgConnection $driverConnection = null;

    /** @var list<Connection> */
    private array $dbalConnections = [];

    /** @var list<string> */
    private array $tablesToDrop = [];

    protected function setUp(): void
    {
        $this->driverConnection = IntegrationConnectionFactory::createConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->dbalConnections as $connection) {
            try {
                $connection->close();
            } catch (Throwable) {
            }
        }

        $this->dbalConnections = [];

        if ($this->driverConnection !== null) {
            foreach ($this->tablesToDrop as $table) {
                try {
                    $this->driverConnection->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
                } catch (Throwable) {
                }
            }

            IntegrationConnectionFactory::close($this->driverConnection);
            $this->driverConnection = null;
        }

        $this->tablesToDrop = [];
    }

    public function testDbalConnectionCloseRollsBackActiveTransactionAndClosesNativePool(): void
    {
        $table = $this->registerTable('operational_close_rows');
        $dbalConnection = $this->createDbalConnection();

        $dbalConnection->executeStatement(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)'
        );
        $dbalConnection->beginTransaction();
        $dbalConnection->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (1, 'alpha')"
        );

        $nativeConnection = $dbalConnection->getNativeConnection();

        $dbalConnection->close();

        self::assertInstanceOf(Closable::class, $nativeConnection);
        self::assertTrue($nativeConnection->isClosed());

        $verificationConnection = $this->createDbalConnection();
        self::assertSame(0, (int) $verificationConnection->fetchOne('SELECT COUNT(*) FROM ' . $table));
    }

    public function testRollbackAfterFailedQueryRestoresUsabilityWithoutDanglingState(): void
    {
        $this->driverConnection()->beginTransaction();

        try {
            $this->driverConnection()->query('SELECT * FROM missing_operational_table')->free();
            self::fail('Expected missing-table failure was not thrown.');
        } catch (AmpPgDriverException) {
        }

        self::assertTrue($this->driverConnection()->leaseManager()->transactionScope()->isActive());

        $this->driverConnection()->rollBack();

        self::assertNull($this->driverConnection()->currentTransactionLease());
        self::assertSame(0, $this->driverConnection()->leaseManager()->activeLeaseCount());
        self::assertSame(1, (int) $this->driverConnection()->query('SELECT 1')->fetchOne());
    }

    public function testRepeatedDbalConnectionCreateAndCloseCyclesRemainUsable(): void
    {
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $connection = $this->createDbalConnection();
            $nativeConnection = $connection->getNativeConnection();

            self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));

            $connection->close();

            self::assertInstanceOf(Closable::class, $nativeConnection);
        }
    }

    private function driverConnection(): AmpPgConnection
    {
        return $this->driverConnection ?? throw new \RuntimeException('Driver connection is not available.');
    }

    private function createDbalConnection(): Connection
    {
        $connection = IntegrationConnectionFactory::createDbalConnection();
        $this->dbalConnections[] = $connection;

        return $connection;
    }

    private function registerTable(string $suffix): string
    {
        $table = IntegrationConnectionFactory::uniqueTableName($suffix);
        $this->tablesToDrop[] = $table;

        return $table;
    }
}
