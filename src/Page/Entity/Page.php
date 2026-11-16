<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\PageSubject;

/**
 * A page in a site's page tree, with one {@see PageTranslation} per locale it is written in.
 *
 * The tree keeps three things in step: the parent, the position among the parent's children (from 0), and the
 * materialized path of ids from the top (`/3/12/40/`), which finds a whole branch with one query. The
 * {@see \SightPress\Core\Page\PageEditor} is the only thing that changes them. All times are UTC.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pages')]
final class Page implements PageSubject
{
    /** Pages can be nested this many levels deep, the top level included. */
    public const int MAX_DEPTH = 10;

    public const string DEFAULT_LAYOUT = 'default';

    private const string LAYOUT_PATTERN = '/^[a-z][a-z0-9_-]{0,49}$/';

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(name: 'tree_path', type: Types::STRING, length: 255)]
    private string $treePath = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $depth;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PageState::class)]
    private PageState $state = PageState::Draft;

    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    private bool $public = true;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $layout = self::DEFAULT_LAYOUT;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<string, PageTranslation> */
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /**
     * @param ?int $authorId user id; users live in another module's table, so there is no foreign key
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
        private ?Page $parent,
        int $position,
        #[ORM\Column(name: 'author_id', type: Types::INTEGER, nullable: true)]
        private ?int $authorId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
        $this->position = max(0, $position);
        $this->depth = $this->parent === null ? 0 : $this->parent->depth + 1;
        if ($this->depth >= self::MAX_DEPTH) {
            throw new PageException(sprintf('Pages can be nested at most %d levels deep.', self::MAX_DEPTH));
        }
        $this->updatedAt = $createdAt;
        $this->translations = new ArrayCollection();
    }

    /**
     * The id, once the page has been saved.
     */
    public function id(): ?int
    {
        return $this->id;
    }

    public function parent(): ?Page
    {
        return $this->parent;
    }

    /**
     * Position among the parent's children, from 0.
     */
    public function position(): int
    {
        return $this->position;
    }

    /**
     * Ids from the top of the tree down to this page, for example `/3/12/40/`; empty until the page has an id.
     */
    public function treePath(): string
    {
        return $this->treePath;
    }

    /**
     * 0 for a top-level page, 1 for its children and so on.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    public function authorId(): ?int
    {
        return $this->authorId;
    }

    public function state(): PageState
    {
        return $this->state;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * Name of the theme layout the page uses, for example `default` or `home`.
     */
    public function layout(): string
    {
        return $this->layout;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function translation(string $locale): ?PageTranslation
    {
        return $this->translations->get($locale);
    }

    /**
     * @return array<string, PageTranslation> keyed by locale, sorted
     */
    public function translations(): array
    {
        $translations = $this->translations->toArray();
        ksort($translations);

        return $translations;
    }

    /**
     * Whether `$page` is somewhere below this page.
     */
    public function isAncestorOf(Page $page): bool
    {
        return $page !== $this && $this->treePath !== '' && str_starts_with($page->treePath, $this->treePath);
    }

    /**
     * Sets the materialized path once the page has its id.
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function placeInTree(): void
    {
        if ($this->id === null) {
            throw new PageException('A page needs to be saved before it gets its place in the tree.');
        }
        $this->treePath = ($this->parent->treePath ?? '/') . $this->id . '/';
    }

    /**
     * Moves the page under another parent (or to the top). Its children follow with {@see followParent()}.
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function moveTo(?Page $parent, int $position, DateTimeImmutable $now): void
    {
        $this->parent = $parent;
        $this->depth = $parent === null ? 0 : $parent->depth + 1;
        $this->position = max(0, $position);
        $this->placeInTree();
        $this->updatedAt = $now;
    }

    /**
     * Takes the new place in the tree after the parent moved.
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function followParent(): void
    {
        if ($this->parent !== null) {
            $this->depth = $this->parent->depth + 1;
        }
        $this->placeInTree();
    }

    /**
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function setPosition(int $position): void
    {
        $this->position = max(0, $position);
    }

    /**
     * Writes or rewrites the page in one locale.
     *
     * @param string $path the full address without the leading slash and locale prefix, for example `about/team`
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function write(string $locale, PageContent $content, string $path, DateTimeImmutable $now): PageTranslation
    {
        $translation = $this->translations->get($locale);
        if ($translation === null) {
            $translation = new PageTranslation($this, $locale, $content, $path, $now);
            $this->translations->set($locale, $translation);
        } else {
            $translation->rewrite($content, $path, $now);
        }
        $this->updatedAt = $now;

        return $translation;
    }

    /**
     * Moves the page to another workflow state. Only the page workflow calls this; it checks the transition first.
     */
    public function changeState(PageState $state, DateTimeImmutable $now): void
    {
        $this->state = $state;
        $this->updatedAt = $now;
    }

    public function setPublic(bool $public, DateTimeImmutable $now): void
    {
        $this->public = $public;
        $this->updatedAt = $now;
    }

    public function setLayout(string $layout, DateTimeImmutable $now): void
    {
        if (preg_match(self::LAYOUT_PATTERN, $layout) !== 1) {
            throw new PageException(sprintf('"%s" is not a layout name such as "default" or "home".', $layout));
        }
        $this->layout = $layout;
        $this->updatedAt = $now;
    }

    /**
     * Everything about the page that a revision keeps.
     *
     * @return array{parent: ?int, position: int, layout: string, public: bool, state: string, translations: array<string, array{title: string, slug: string, path: string, summary: ?string, description: ?string, blocks: list<array{type: string, version: int, data: array<string, mixed>}>}>}
     */
    public function snapshot(): array
    {
        return [
            'parent' => $this->parent?->id,
            'position' => $this->position,
            'layout' => $this->layout,
            'public' => $this->public,
            'state' => $this->state->value,
            'translations' => array_map(
                static fn (PageTranslation $translation): array => $translation->snapshot(),
                $this->translations(),
            ),
        ];
    }
}
