<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Throwable;

final class OrmSchemaManager
{
    public static function recreate(EntityManagerInterface $entityManager): void
    {
        $metadata = self::metadata($entityManager);
        $schemaTool = new SchemaTool($entityManager);

        try {
            $schemaTool->dropDatabase();
        } catch (Throwable) {
        }

        $schemaTool->createSchema($metadata);
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private static function metadata(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getMetadataFactory()->getAllMetadata();
    }
}
