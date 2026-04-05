<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Unit\Transport;

use Amp\Postgres\PostgresArray;
use Amp\Postgres\PostgresByteA;
use Dorpmaster\DbalAmpPostgres\Transport\Exception\UnsupportedParameterType;
use Dorpmaster\DbalAmpPostgres\Transport\ParameterConverter;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class ParameterConverterTest extends TestCase
{
    public function testScalarValuesPassThroughPredictably(): void
    {
        $converter = new ParameterConverter();

        self::assertNull($converter->convertValue(null, ParameterType::NULL));
        self::assertTrue($converter->convertValue(true, ParameterType::BOOLEAN));
        self::assertSame(42, $converter->convertValue(42, ParameterType::INTEGER));
        self::assertSame(1.5, $converter->convertValue(1.5, ParameterType::STRING));
        self::assertSame('demo', $converter->convertValue('demo', ParameterType::STRING));
        self::assertSame('ascii', $converter->convertValue('ascii', ParameterType::ASCII));

        $binary = $converter->convertValue('binary', ParameterType::BINARY);
        self::assertInstanceOf(PostgresByteA::class, $binary);
        self::assertSame('binary', $binary->getData());
    }

    public function testConvertParametersUsesProvidedTypes(): void
    {
        $converter = new ParameterConverter();

        self::assertSame(
            [
                1 => true,
                'name' => 'demo',
            ],
            $converter->convertParameters(
                [
                    1 => true,
                    'name' => 'demo',
                ],
                [
                    1 => ParameterType::BOOLEAN,
                    'name' => ParameterType::STRING,
                ],
            ),
        );
    }

    public function testSequentialScalarArraysAreConvertedToPostgresArray(): void
    {
        $converter = new ParameterConverter();

        $converted = $converter->convertValue(['alpha', 'beta'], ParameterType::STRING);

        self::assertInstanceOf(PostgresArray::class, $converted);
    }

    public function testAssociativeArraysAreRejectedExplicitly(): void
    {
        $converter = new ParameterConverter();

        $this->expectException(UnsupportedParameterType::class);
        $converter->convertValue(['name' => 'alpha'], ParameterType::STRING);
    }

    public function testMixedScalarArraysAreRejectedExplicitly(): void
    {
        $converter = new ParameterConverter();

        $this->expectException(UnsupportedParameterType::class);
        $converter->convertValue([1, 'two'], ParameterType::STRING);
    }

    public function testMultidimensionalArraysAreRejectedExplicitly(): void
    {
        $converter = new ParameterConverter();

        $this->expectException(UnsupportedParameterType::class);
        $converter->convertValue([['nested']], ParameterType::STRING);
    }

    public function testNullArrayElementsAreRejectedExplicitly(): void
    {
        $converter = new ParameterConverter();

        $this->expectException(UnsupportedParameterType::class);
        $converter->convertValue(['alpha', null], ParameterType::STRING);
    }

    public function testObjectsAreRejectedExplicitly(): void
    {
        $converter = new ParameterConverter();

        $this->expectException(UnsupportedParameterType::class);
        $converter->convertValue((object) ['value' => 'x'], ParameterType::STRING);
    }
}
