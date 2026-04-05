<?php

declare(strict_types=1);

namespace Dorpmaster\DbalAmpPostgres\Tests\Integration\Orm\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orm_smoke_posts')]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'orm_smoke_posts_id_seq', allocationSize: 1, initialValue: 1)]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'posts', fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    public function __construct(string $title, User $author)
    {
        $this->title = $title;
        $this->author = $author;
        $author->addPost($this);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function author(): User
    {
        return $this->author;
    }

    public function assignAuthor(User $author): void
    {
        $this->author = $author;
    }
}
