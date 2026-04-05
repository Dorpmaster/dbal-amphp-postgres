<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Runtime;

use Dorpmaster\DbalAmpPostgres\Runtime\TransactionScope;
use LogicException;
use PHPUnit\Framework\TestCase;

final class TransactionScopeTest extends TestCase
{
    public function testDepthIncreaseDecreaseAndReset(): void
    {
        $scope = new TransactionScope();

        self::assertSame(0, $scope->depth());
        self::assertFalse($scope->isActive());

        $scope->increase();
        $scope->increase();

        self::assertSame(2, $scope->depth());
        self::assertTrue($scope->isActive());

        $scope->decrease();

        self::assertSame(1, $scope->depth());
        self::assertTrue($scope->isActive());

        $scope->reset();

        self::assertSame(0, $scope->depth());
        self::assertFalse($scope->isActive());
    }

    public function testBeginCommitRollbackHelpersTrackNestedState(): void
    {
        $scope = new TransactionScope();

        $scope->begin();

        self::assertTrue($scope->isOutermost());
        self::assertTrue($scope->willBecomeInactiveAfterDecrease());

        $scope->begin();

        self::assertFalse($scope->isOutermost());
        self::assertFalse($scope->willBecomeInactiveAfterDecrease());

        $scope->commitLevel();

        self::assertSame(1, $scope->depth());
        self::assertTrue($scope->isOutermost());

        $scope->rollbackLevel();

        self::assertSame(0, $scope->depth());
        self::assertFalse($scope->isActive());
    }

    public function testDecreaseBelowZeroFails(): void
    {
        $scope = new TransactionScope();

        $this->expectException(LogicException::class);
        $scope->decrease();
    }

    public function testWillBecomeInactiveFailsWhenScopeIsInactive(): void
    {
        $scope = new TransactionScope();

        $this->expectException(LogicException::class);
        $scope->willBecomeInactiveAfterDecrease();
    }
}
