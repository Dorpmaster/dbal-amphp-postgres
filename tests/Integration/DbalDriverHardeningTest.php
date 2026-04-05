<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Throwable;

final class DbalDriverHardeningTest extends TestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $tablesToDrop = [];

    protected function setUp(): void
    {
        $this->connection = IntegrationConnectionFactory::createDbalConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->connection->isTransactionActive()) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable) {
                }
            }

            foreach ($this->tablesToDrop as $table) {
                try {
                    $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
                } catch (Throwable) {
                }
            }

            IntegrationConnectionFactory::closeDbalConnection($this->connection);
            $this->connection = null;
        }

        $this->tablesToDrop = [];
    }

    public function testNestedTransactionsInnerCommitAndOuterCommitPersistAllChanges(): void
    {
        $table = $this->registerTable('nested_commit_rows');

        $this->connection()->executeStatement(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)'
        );

        $this->connection()->beginTransaction();
        $outerPid = $this->fetchBackendPid();
        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (1, 'outer')"
        );

        $this->connection()->beginTransaction();
        $innerPid = $this->fetchBackendPid();
        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (2, 'inner')"
        );
        $this->connection()->commit();
        $this->connection()->commit();

        self::assertSame($outerPid, $innerPid);
        self::assertSame(
            [
                ['id' => 1, 'value' => 'outer'],
                ['id' => 2, 'value' => 'inner'],
            ],
            $this->connection()->fetchAllAssociative('SELECT id, value FROM ' . $table . ' ORDER BY id')
        );
    }

    public function testNestedTransactionsInnerRollbackPreservesOuterTransaction(): void
    {
        $table = $this->registerTable('nested_rollback_rows');

        $this->connection()->executeStatement(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, value TEXT NOT NULL)'
        );

        $this->connection()->beginTransaction();
        $outerPid = $this->fetchBackendPid();
        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (1, 'outer')"
        );

        $this->connection()->beginTransaction();
        $innerPid = $this->fetchBackendPid();
        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (2, 'inner')"
        );
        $this->connection()->rollBack();

        self::assertSame($outerPid, $innerPid);

        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, value) VALUES (3, 'outer-after-rollback')"
        );
        $this->connection()->commit();

        self::assertSame(
            [
                ['id' => 1, 'value' => 'outer'],
                ['id' => 3, 'value' => 'outer-after-rollback'],
            ],
            $this->connection()->fetchAllAssociative('SELECT id, value FROM ' . $table . ' ORDER BY id')
        );
    }

    public function testAbortedTransactionRequiresRollbackBeforeFurtherQueries(): void
    {
        $this->connection()->beginTransaction();

        try {
            $this->connection()->executeQuery('SELECT * FROM missing_table')->free();
            self::fail('Expected table-not-found failure was not thrown.');
        } catch (TableNotFoundException) {
        }

        self::assertTrue($this->connection()->isTransactionActive());

        try {
            $this->connection()->fetchOne('SELECT 1');
            self::fail('Expected aborted-transaction failure was not thrown.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('current transaction is aborted', $exception->getMessage());
        }

        $this->connection()->rollBack();

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT 1'));
    }

    public function testUniqueViolationIsConvertedToDbalException(): void
    {
        $table = $this->registerTable('unique_violation_rows');

        $this->connection()->executeStatement(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, email TEXT NOT NULL UNIQUE)'
        );
        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, email) VALUES (1, 'alice@example.com')"
        );

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection()->executeStatement(
            'INSERT INTO ' . $table . " (id, email) VALUES (2, 'alice@example.com')"
        );
    }

    public function testForeignKeyViolationIsConvertedToDbalException(): void
    {
        $parentTable = $this->registerTable('fk_parent_rows');
        $childTable = $this->registerTable('fk_child_rows');

        $this->connection()->executeStatement(
            'CREATE TABLE ' . $parentTable . ' (id INT PRIMARY KEY)'
        );
        $this->connection()->executeStatement(
            'CREATE TABLE ' . $childTable
            . ' (id INT PRIMARY KEY, parent_id INT NOT NULL REFERENCES ' . $parentTable . ' (id))'
        );

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection()->executeStatement(
            'INSERT INTO ' . $childTable . ' (id, parent_id) VALUES (1, 999)'
        );
    }

    public function testSyntaxErrorIsConvertedToDbalException(): void
    {
        $this->expectException(SyntaxErrorException::class);

        $this->connection()->executeQuery('SELEC 1')->free();
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \RuntimeException('DBAL connection is not available.');
    }

    private function registerTable(string $suffix): string
    {
        $table = IntegrationConnectionFactory::uniqueTableName($suffix);
        $this->tablesToDrop[] = $table;

        return $table;
    }

    private function fetchBackendPid(): int
    {
        return (int) $this->connection()->fetchOne('SELECT pg_backend_pid()');
    }
}
