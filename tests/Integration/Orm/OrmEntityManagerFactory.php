<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDbalConnection;
use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver;
use Dorpmaster\DbalAmpPostgres\Tests\Integration\IntegrationConnectionFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

final class OrmEntityManagerFactory
{
    public static function create(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig(
            paths: [__DIR__ . '/Entity'],
            isDevMode: true,
        );
        $configuration->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection([
            ...IntegrationConnectionFactory::params(),
            'driverClass' => AmpPgDriver::class,
            'wrapperClass' => AmpPgDbalConnection::class,
        ]);

        return new EntityManager($connection, $configuration);
    }

    public static function close(EntityManager $entityManager): void
    {
        $connection = $entityManager->getConnection();

        $connection->close();
        $entityManager->close();
    }
}
