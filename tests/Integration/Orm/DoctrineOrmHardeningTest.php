<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm;

use Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity\Post;
use Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity\User;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

use function array_map;

final class DoctrineOrmHardeningTest extends TestCase
{
    private ?EntityManager $entityManager = null;

    protected function setUp(): void
    {
        $this->entityManager = OrmEntityManagerFactory::create();
        OrmSchemaManager::recreate($this->entityManager);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            OrmEntityManagerFactory::close($this->entityManager);
            $this->entityManager = null;
        }
    }

    public function testOneToManyCollectionLazyLoadsAfterClear(): void
    {
        $graph = $this->persistSampleGraph();
        $user = $graph[0];
        $this->entityManager()->clear();

        $reloadedUser = $this->entityManager()->find(User::class, $user->id());

        self::assertInstanceOf(User::class, $reloadedUser);
        self::assertCount(2, $reloadedUser->posts());
        self::assertSame(
            ['First post', 'Second post'],
            array_map(
                static fn (Post $post): string => $post->title(),
                $reloadedUser->posts()->toArray(),
            )
        );
    }

    public function testLazyLoadingAfterClearOnManyToOneAssociation(): void
    {
        $graph = $this->persistSampleGraph();
        $firstPost = $graph[1];
        $this->entityManager()->clear();

        $reloadedPost = $this->entityManager()->find(Post::class, $firstPost->id());

        self::assertInstanceOf(Post::class, $reloadedPost);
        self::assertSame('Alice', $reloadedPost->author()->name());
    }

    public function testLazyLoadingInsideTransactionUsesSameBackendConnection(): void
    {
        $graph = $this->persistSampleGraph();
        $user = $graph[0];
        $this->entityManager()->clear();

        $this->entityManager()->beginTransaction();

        try {
            $beforeLazyLoadPid = (int) $this->entityManager()
                ->getConnection()
                ->fetchOne('SELECT pg_backend_pid()');

            $reloadedUser = $this->entityManager()->find(User::class, $user->id());
            self::assertInstanceOf(User::class, $reloadedUser);
            self::assertCount(2, $reloadedUser->posts());

            $afterLazyLoadPid = (int) $this->entityManager()
                ->getConnection()
                ->fetchOne('SELECT pg_backend_pid()');

            self::assertSame($beforeLazyLoadPid, $afterLazyLoadPid);

            $this->entityManager()->commit();
        } catch (\Throwable $throwable) {
            if ($this->entityManager()->getConnection()->isTransactionActive()) {
                $this->entityManager()->rollback();
            }

            throw $throwable;
        }
    }

    /**
     * @return array{0: User, 1: Post, 2: Post}
     */
    private function persistSampleGraph(): array
    {
        $user = new User('Alice', 'alice@example.com');
        $firstPost = new Post('First post', $user);
        $secondPost = new Post('Second post', $user);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($firstPost);
        $this->entityManager()->persist($secondPost);
        $this->entityManager()->flush();

        return [$user, $firstPost, $secondPost];
    }

    private function entityManager(): EntityManager
    {
        return $this->entityManager ?? throw new \RuntimeException('EntityManager is not available.');
    }
}
