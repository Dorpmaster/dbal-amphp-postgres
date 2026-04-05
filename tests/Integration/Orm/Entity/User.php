<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orm_smoke_users')]
#[ORM\UniqueConstraint(name: 'uniq_orm_smoke_users_email', columns: ['email'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'orm_smoke_users_id_seq', allocationSize: 1, initialValue: 1)]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $email;

    /** @var Collection<int, Post> */
    #[ORM\OneToMany(
        targetEntity: Post::class,
        mappedBy: 'author',
        cascade: ['persist'],
        orphanRemoval: true,
        fetch: 'LAZY',
    )]
    private Collection $posts;

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
        $this->posts = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function email(): string
    {
        return $this->email;
    }

    /**
     * @return Collection<int, Post>
     */
    public function posts(): Collection
    {
        return $this->posts;
    }

    public function addPost(Post $post): void
    {
        if ($this->posts->contains($post)) {
            return;
        }

        $this->posts->add($post);
    }
}
