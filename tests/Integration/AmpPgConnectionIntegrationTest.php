<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Throwable;

final class AmpPgConnectionIntegrationTest extends TestCase
{
    private ?AmpPgConnection $connection = null;

    /** @var list<string> */
    private array $tablesToDrop = [];

    protected function setUp(): void
    {
        $this->connection = IntegrationConnectionFactory::createConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ($this->tablesToDrop as $table) {
                try {
                    $this->connection->exec('DROP TABLE IF EXISTS ' . $table);
                } catch (Throwable) {
                }
            }

            IntegrationConnectionFactory::close($this->connection);
            $this->connection = null;
        }

        $this->tablesToDrop = [];
    }

    public function testQueryReturnsRows(): void
    {
        $result = $this->connection()->query("SELECT 1 AS id, 'alpha' AS name");

        self::assertSame(['id' => 1, 'name' => 'alpha'], $result->fetchAssociative());
        self::assertSame(1, $this->connection()->leaseManager()->activeLeaseCount());

        $result->free();

        self::assertSame(0, $this->connection()->leaseManager()->activeLeaseCount());
    }

    public function testExecReturnsAffectedRows(): void
    {
        $table = $this->registerTable('exec_rows');

        $this->connection()->exec('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)');

        self::assertSame(1, $this->connection()->exec("INSERT INTO {$table} (id, value) VALUES (1, 'alpha')"));
        self::assertSame(1, $this->connection()->exec("UPDATE {$table} SET value = 'beta' WHERE id = 1"));
        self::assertSame(1, $this->connection()->exec("DELETE FROM {$table} WHERE id = 1"));
    }

    public function testPreparedStatementSupportsPositionalAndNamedParameters(): void
    {
        $positional = $this->connection()->prepare('SELECT ?::int AS id, ?::text AS name');
        $positional->bindValue(1, 5, ParameterType::INTEGER);
        $positional->bindValue(2, 'alpha', ParameterType::STRING);

        $result = $positional->execute();
        self::assertSame(['id' => 5, 'name' => 'alpha'], $result->fetchAssociative());
        $result->free();

        $named = $this->connection()->prepare('SELECT :id::int AS id, :name::text AS name');
        $named->bindValue('id', 7, ParameterType::INTEGER);
        $named->bindValue('name', 'beta', ParameterType::STRING);

        $result = $named->execute();
        self::assertSame([7, 'beta'], $result->fetchNumeric());
        $result->free();
    }

    public function testTransactionUsesSameBackendConnectionAndCommitPersistsData(): void
    {
        $table = $this->registerTable('commit_rows');

        $this->connection()->exec('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)');

        $this->connection()->beginTransaction();

        $firstPid = $this->fetchBackendPid($this->connection());
        $this->connection()->exec("INSERT INTO {$table} (id, value) VALUES (1, 'alpha')");
        $secondPid = $this->fetchBackendPid($this->connection());

        self::assertSame($firstPid, $secondPid);

        $this->connection()->commit();

        $verificationConnection = IntegrationConnectionFactory::createConnection();
        $result = $verificationConnection->query('SELECT COUNT(*) AS count FROM ' . $table);

        self::assertSame(1, $result->fetchOne());
        $result->free();
        IntegrationConnectionFactory::close($verificationConnection);
    }

    public function testTransactionRollbackDiscardsData(): void
    {
        $table = $this->registerTable('rollback_rows');

        $this->connection()->exec('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)');

        $this->connection()->beginTransaction();
        $this->connection()->exec("INSERT INTO {$table} (id, value) VALUES (1, 'alpha')");
        $this->connection()->rollBack();

        $result = $this->connection()->query('SELECT COUNT(*) AS count FROM ' . $table);
        self::assertSame(0, $result->fetchOne());
        $result->free();
    }

    public function testResultLifetimeKeepsLeasePinnedUntilFree(): void
    {
        $result = $this->connection()->query('SELECT generate_series(1, 3) AS id');

        self::assertSame(1, $this->connection()->leaseManager()->activeLeaseCount());
        self::assertSame(1, $this->connection()->leaseManager()->activeResultCount());

        $result->free();

        self::assertSame(0, $this->connection()->leaseManager()->activeLeaseCount());
        self::assertSame(0, $this->connection()->leaseManager()->activeResultCount());
    }

    public function testFailingSqlDoesNotLeaveDanglingLeaseState(): void
    {
        $this->expectException(Throwable::class);

        try {
            $this->connection()->query('SELECT * FROM missing_table');
        } finally {
            self::assertSame(0, $this->connection()->leaseManager()->activeLeaseCount());
            self::assertNull($this->connection()->currentTransactionLease());
        }
    }

    private function connection(): AmpPgConnection
    {
        return $this->connection ?? throw new \RuntimeException('Integration connection is not available.');
    }

    private function registerTable(string $suffix): string
    {
        $table = IntegrationConnectionFactory::uniqueTableName($suffix);
        $this->tablesToDrop[] = $table;

        return $table;
    }

    private function fetchBackendPid(AmpPgConnection $connection): int
    {
        $result = $connection->query('SELECT pg_backend_pid() AS pid');
        $pid = (int) $result->fetchOne();
        $result->free();

        return $pid;
    }
}
