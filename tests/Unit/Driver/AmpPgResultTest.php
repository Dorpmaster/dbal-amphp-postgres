<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Driver;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgResult;
use Closure;
use PHPUnit\Framework\TestCase;

final class AmpPgResultTest extends TestCase
{
    public function testFetchNumericReturnsSequentialRows(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame([1, 'alpha'], $result->fetchNumeric());
        self::assertSame([2, 'beta'], $result->fetchNumeric());
        self::assertFalse($result->fetchNumeric());
    }

    public function testFetchAssociativeReturnsSequentialRows(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame(['id' => 1, 'name' => 'alpha'], $result->fetchAssociative());
        self::assertSame(['id' => 2, 'name' => 'beta'], $result->fetchAssociative());
        self::assertFalse($result->fetchAssociative());
    }

    public function testFetchOneReturnsFirstColumnValue(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame(1, $result->fetchOne());
        self::assertSame(2, $result->fetchOne());
        self::assertFalse($result->fetchOne());
    }

    public function testFetchAllNumericReturnsAllRows(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame([[1, 'alpha'], [2, 'beta']], $result->fetchAllNumeric());
    }

    public function testFetchAllAssociativeReturnsAllRows(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame(
            [
                ['id' => 1, 'name' => 'alpha'],
                ['id' => 2, 'name' => 'beta'],
            ],
            $result->fetchAllAssociative(),
        );
    }

    public function testFetchFirstColumnReturnsFirstColumnValues(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame([1, 2], $result->fetchFirstColumn());
    }

    public function testRowCountAndColumnCountReflectBuffer(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ]);

        self::assertSame(2, $result->rowCount());
        self::assertSame(2, $result->columnCount());
    }

    public function testFreeMakesFurtherAccessSafe(): void
    {
        $result = new AmpPgResult([
            ['id' => 1, 'name' => 'alpha'],
        ]);

        $result->free();

        self::assertFalse($result->fetchNumeric());
        self::assertFalse($result->fetchAssociative());
        self::assertFalse($result->fetchOne());
        self::assertSame([], $result->fetchAllNumeric());
        self::assertSame([], $result->fetchAllAssociative());
        self::assertSame([], $result->fetchFirstColumn());
        self::assertSame(0, $result->rowCount());
        self::assertSame(0, $result->columnCount());
    }

    public function testMixedKeyRowsPreferAssociativeShapeDeterministically(): void
    {
        $result = new AmpPgResult([
            [
                0 => 'ignored-numeric',
                'id' => 10,
                'name' => 'alpha',
                1 => 'ignored-too',
            ],
        ]);

        self::assertSame([10, 'alpha'], $result->fetchNumeric());

        $result = new AmpPgResult([
            [
                0 => 'ignored-numeric',
                'id' => 10,
                'name' => 'alpha',
                1 => 'ignored-too',
            ],
        ]);

        self::assertSame(['id' => 10, 'name' => 'alpha'], $result->fetchAssociative());
    }

    public function testNumericRowsWithSparseIndexesAreNormalizedPredictably(): void
    {
        $result = new AmpPgResult([
            [
                2 => 'alpha',
                5 => 'beta',
            ],
        ]);

        self::assertSame(['alpha', 'beta'], $result->fetchNumeric());

        $result = new AmpPgResult([
            [
                2 => 'alpha',
                5 => 'beta',
            ],
        ]);

        self::assertSame(['0' => 'alpha', '1' => 'beta'], $result->fetchAssociative());
    }

    public function testOnFreeCallbackIsCalledExactlyOnce(): void
    {
        $callCount = 0;

        $result = new AmpPgResult(
            rows: [
                ['id' => 1, 'name' => 'alpha'],
            ],
            onFree: Closure::fromCallable(static function () use (&$callCount): void {
                $callCount++;
            }),
        );

        $result->free();
        $result->free();

        self::assertSame(1, $callCount);
    }
}
