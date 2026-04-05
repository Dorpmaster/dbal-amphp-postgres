<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm;

use Dorpmaster\DbalAmpPostgres\Driver\AmpPgDriver;
use Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity\Post;
use Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class DoctrineOrmSmokeTest extends TestCase
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

    public function testBootEntityManagerAndBasicOrmQuery(): void
    {
        self::assertInstanceOf(AmpPgDriver::class, $this->entityManager()->getConnection()->getDriver());

        $count = $this->entityManager()
            ->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')
            ->getSingleScalarResult();

        self::assertSame(0, (int) $count);
    }

    public function testPersistFlushAndFind(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $userId = $user->id();
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(User::class, $userId);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Alice', $reloaded->name());
        self::assertSame('alice@example.com', $reloaded->email());
    }

    public function testUpdateAndFlush(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $user->rename('Alice Updated');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(User::class, $user->id());

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Alice Updated', $reloaded->name());
    }

    public function testRemoveAndFlush(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $userId = $user->id();

        $this->entityManager()->remove($user);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertNull($this->entityManager()->find(User::class, $userId));
    }

    public function testRelationLoadingBaseline(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $post = new Post('First post', $user);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($post);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $reloadedPost = $this->entityManager()->find(Post::class, $post->id());

        self::assertInstanceOf(Post::class, $reloadedPost);
        self::assertSame('Alice', $reloadedPost->author()->name());
    }

    public function testLazyLoadingBaselineOnPostAuthorAssociation(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $post = new Post('First post', $user);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($post);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $reloadedPost = $this->entityManager()->find(Post::class, $post->id());

        self::assertInstanceOf(Post::class, $reloadedPost);
        self::assertSame('Alice', $reloadedPost->author()->name());
        self::assertSame('alice@example.com', $reloadedPost->author()->email());
    }

    public function testDqlQueryWithParameters(): void
    {
        $this->persistSampleGraph();
        $this->entityManager()->clear();

        $users = $this->entityManager()
            ->createQuery('SELECT u FROM ' . User::class . ' u WHERE u.email = :email')
            ->setParameter('email', 'alice@example.com')
            ->getResult();

        self::assertCount(1, $users);
        self::assertSame('Alice', $users[0]->name());
    }

    public function testQueryBuilderBasicPath(): void
    {
        $this->persistSampleGraph();
        $this->entityManager()->clear();

        $posts = $this->entityManager()
            ->createQueryBuilder()
            ->select('p')
            ->from(Post::class, 'p')
            ->where('p.title LIKE :prefix')
            ->setParameter('prefix', 'First%')
            ->getQuery()
            ->getResult();

        self::assertCount(1, $posts);
        self::assertSame('First post', $posts[0]->title());
    }

    public function testOrmTransactionCommitUsesSameBackendConnection(): void
    {
        $this->entityManager()->beginTransaction();

        $firstPid = (int) $this->entityManager()->getConnection()->fetchOne('SELECT pg_backend_pid()');

        $user = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $secondPid = (int) $this->entityManager()->getConnection()->fetchOne('SELECT pg_backend_pid()');

        $this->entityManager()->commit();
        $this->entityManager()->clear();

        self::assertSame($firstPid, $secondPid);
        self::assertInstanceOf(User::class, $this->entityManager()->find(User::class, $user->id()));
    }

    public function testOrmTransactionRollbackDiscardsData(): void
    {
        $this->entityManager()->beginTransaction();

        $user = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $this->entityManager()->rollback();
        $this->entityManager()->clear();

        $count = $this->entityManager()
            ->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')
            ->getSingleScalarResult();

        self::assertSame(0, (int) $count);
    }

    public function testFailedFlushLeavesEntityManagerClosedAndDataRollbackPredictable(): void
    {
        $existingUser = new User('Alice', 'alice@example.com');
        $this->entityManager()->persist($existingUser);
        $this->entityManager()->flush();

        $this->entityManager()->beginTransaction();

        $duplicateUser = new User('Bob', 'alice@example.com');
        $this->entityManager()->persist($duplicateUser);

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $this->entityManager()->flush();
        } finally {
            if ($this->entityManager()->getConnection()->isTransactionActive()) {
                $this->entityManager()->rollback();
            }

            self::assertFalse($this->entityManager()->isOpen());

            OrmEntityManagerFactory::close($this->entityManager());
            $this->entityManager = OrmEntityManagerFactory::create();

            $count = $this->entityManager()
                ->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')
                ->getSingleScalarResult();

            self::assertSame(1, (int) $count);
        }
    }

    private function entityManager(): EntityManager
    {
        return $this->entityManager ?? throw new \RuntimeException('EntityManager is not available.');
    }

    private function persistSampleGraph(): void
    {
        $user = new User('Alice', 'alice@example.com');
        $firstPost = new Post('First post', $user);
        $secondPost = new Post('Second post', $user);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($firstPost);
        $this->entityManager()->persist($secondPost);
        $this->entityManager()->flush();
    }
}
