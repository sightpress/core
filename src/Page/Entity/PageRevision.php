<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Page\PageException;

/**
 * A copy of a page as it was after one save: its place in the tree, its settings and every translation with its
 * blocks. Numbered from 1 per page. Never changes once written.
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_revisions')]
#[ORM\UniqueConstraint(name: 'page_revisions_page_revision_unique', columns: ['page_id', 'revision'])]
final class PageRevision
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    /**
     * @param ?int $savedBy user id, or null for the system (for example the console)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Page::class)]
        #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Page $page,
        #[ORM\Column(name: 'revision', type: Types::INTEGER)]
        private int $number,
        #[ORM\Column(name: 'saved_by', type: Types::INTEGER, nullable: true)]
        private ?int $savedBy,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
        if ($number < 1) {
            throw new PageException('Revisions are numbered from 1.');
        }
        $this->snapshot = $page->snapshot();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function page(): Page
    {
        return $this->page;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function savedBy(): ?int
    {
        return $this->savedBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The page as it was, in the shape of {@see Page::snapshot()}.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }
}
