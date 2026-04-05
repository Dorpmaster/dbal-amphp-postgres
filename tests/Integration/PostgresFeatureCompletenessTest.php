<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use Throwable;

use function json_decode;

final class PostgresFeatureCompletenessTest extends TestCase
{
    private ?AmpPgConnection $driverConnection = null;

    private ?Connection $dbalConnection = null;

    /** @var list<string> */
    private array $tablesToDrop = [];

    /** @var list<string> */
    private array $sequencesToDrop = [];

    protected function setUp(): void
    {
        $this->driverConnection = IntegrationConnectionFactory::createConnection();
        $this->dbalConnection = IntegrationConnectionFactory::createDbalConnection();
    }

    protected function tearDown(): void
    {
        if ($this->dbalConnection !== null) {
            if ($this->dbalConnection->isTransactionActive()) {
                try {
                    $this->dbalConnection->rollBack();
                } catch (Throwable) {
                }
            }

            foreach ($this->tablesToDrop as $table) {
                try {
                    $this->dbalConnection->executeStatement('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
                } catch (Throwable) {
                }
            }

            foreach ($this->sequencesToDrop as $sequence) {
                try {
                    $this->dbalConnection->executeStatement('DROP SEQUENCE IF EXISTS ' . $sequence . ' CASCADE');
                } catch (Throwable) {
                }
            }

            IntegrationConnectionFactory::closeDbalConnection($this->dbalConnection);
            $this->dbalConnection = null;
        }

        if ($this->driverConnection !== null) {
            IntegrationConnectionFactory::close($this->driverConnection);
            $this->driverConnection = null;
        }

        $this->tablesToDrop = [];
        $this->sequencesToDrop = [];
    }

    public function testJsonbRoundTripUsesExplicitJsonTypeConversion(): void
    {
        $table = $this->registerTable('feature_jsonb_rows');

        $this->dbalConnection()->executeStatement(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, payload JSONB NOT NULL)'
        );

        $payload = [
            'name' => 'alpha',
            'flags' => [true, false],
            'count' => 2,
        ];

        $this->dbalConnection()->executeStatement(
            'INSERT INTO ' . $table . ' (id, payload) VALUES (?, ?)',
            [1, $payload],
            [ParameterType::INTEGER, Types::JSONB],
        );

        $storedPayload = $this->dbalConnection()->fetchOne('SELECT payload FROM ' . $table . ' WHERE id = 1');

        self::assertIsString($storedPayload);
        $decodedPayload = json_decode($storedPayload, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decodedPayload);
        self::assertSame('alpha', $decodedPayload['name']);
        self::assertSame(2, $decodedPayload['count']);
        self::assertSame([true, false], $decodedPayload['flags']);
    }

    public function testOneDimensionalScalarArraysRoundTripThroughPreparedStatements(): void
    {
        $table = $this->registerTable('feature_array_rows');

        $this->driverConnection()->exec(
            'CREATE TABLE '
            . $table
            . ' (id INT PRIMARY KEY, tags TEXT[] NOT NULL, numbers INT[] NOT NULL, flags BOOLEAN[] NOT NULL)'
        );

        $statement = $this->driverConnection()->prepare(
            'INSERT INTO ' . $table . ' (id, tags, numbers, flags) VALUES (?, ?, ?, ?)'
        );
        $statement->bindValue(1, 1, ParameterType::INTEGER);
        $statement->bindValue(2, ['alpha', 'beta'], ParameterType::STRING);
        $statement->bindValue(3, [1, 2, 3], ParameterType::STRING);
        $statement->bindValue(4, [true, false], ParameterType::STRING);
        $statement->execute()->free();

        $result = $this->driverConnection()->query(
            'SELECT tags, numbers, flags FROM ' . $table . ' WHERE id = 1'
        );

        self::assertSame(
            [
                'tags' => ['alpha', 'beta'],
                'numbers' => [1, 2, 3],
                'flags' => [true, false],
            ],
            $result->fetchAssociative(),
        );

        $result->free();
    }

    public function testByteaRoundTripPreservesBinaryPayload(): void
    {
        $table = $this->registerTable('feature_bytea_rows');
        $payload = "\x00\x01\x02binary\x7f\xff";

        $this->driverConnection()->exec(
            'CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, payload BYTEA NOT NULL)'
        );

        $statement = $this->driverConnection()->prepare(
            'INSERT INTO ' . $table . ' (id, payload) VALUES (?, ?)'
        );
        $statement->bindValue(1, 1, ParameterType::INTEGER);
        $statement->bindValue(2, $payload, ParameterType::BINARY);
        $statement->execute()->free();

        $result = $this->driverConnection()->query(
            'SELECT payload FROM ' . $table . ' WHERE id = 1'
        );

        self::assertSame($payload, $result->fetchOne());

        $result->free();
    }

    public function testLastInsertIdUsesLastvalOnPinnedTransactionConnection(): void
    {
        $table = $this->registerTable('feature_sequence_rows');
        $sequence = $this->registerSequence('feature_sequence_rows_id_seq');

        $this->dbalConnection()->executeStatement('CREATE SEQUENCE ' . $sequence . ' START WITH 100');
        $this->dbalConnection()->executeStatement(
            'CREATE TABLE ' . $table
            . ' (id BIGINT PRIMARY KEY DEFAULT nextval('
            . "'" . $sequence . "'"
            . "), value TEXT NOT NULL)"
        );

        $this->dbalConnection()->beginTransaction();
        $this->dbalConnection()->executeStatement(
            'INSERT INTO ' . $table . " (value) VALUES ('alpha')"
        );

        self::assertSame(100, (int) $this->dbalConnection()->lastInsertId());

        $this->dbalConnection()->commit();
    }

    public function testLastInsertIdOutsideTransactionIsExplicitlyUnsupported(): void
    {
        $table = $this->registerTable('feature_sequence_no_tx_rows');
        $sequence = $this->registerSequence('feature_sequence_no_tx_rows_id_seq');

        $this->dbalConnection()->executeStatement('CREATE SEQUENCE ' . $sequence . ' START WITH 1');
        $this->dbalConnection()->executeStatement(
            'CREATE TABLE ' . $table
            . ' (id BIGINT PRIMARY KEY DEFAULT nextval('
            . "'" . $sequence . "'"
            . "), value TEXT NOT NULL)"
        );
        $this->dbalConnection()->executeStatement(
            'INSERT INTO ' . $table . " (value) VALUES ('alpha')"
        );

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('requires an active transaction');

        $this->dbalConnection()->lastInsertId();
    }

    private function driverConnection(): AmpPgConnection
    {
        return $this->driverConnection ?? throw new \RuntimeException('Driver connection is not available.');
    }

    private function dbalConnection(): Connection
    {
        return $this->dbalConnection ?? throw new \RuntimeException('DBAL connection is not available.');
    }

    private function registerTable(string $suffix): string
    {
        $table = IntegrationConnectionFactory::uniqueTableName($suffix);
        $this->tablesToDrop[] = $table;

        return $table;
    }

    private function registerSequence(string $suffix): string
    {
        $sequence = IntegrationConnectionFactory::uniqueTableName($suffix);
        $this->sequencesToDrop[] = $sequence;

        return $sequence;
    }
}
