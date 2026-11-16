<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Tenant\LocaleCode;

/**
 * A page written in one locale: its title, slug, full address, summary, description and blocks.
 *
 * The address is unique per locale, which also keeps every slug unique among the children of one parent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_translations')]
#[ORM\UniqueConstraint(name: 'page_translations_page_locale_unique', columns: ['page_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'page_translations_locale_path_unique', columns: ['locale', 'url_path'])]
final class PageTranslation
{
    public const int PATH_MAX_LENGTH = 620;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $title;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $slug;

    #[ORM\Column(name: 'url_path', type: Types::STRING, length: self::PATH_MAX_LENGTH)]
    private string $path;

    #[ORM\Column(type: Types::STRING, length: 300, nullable: true)]
    private ?string $summary;

    #[ORM\Column(type: Types::STRING, length: 300, nullable: true)]
    private ?string $description;

    /** @var list<array{type: string, version: int, data: array<string, mixed>}> */
    #[ORM\Column(type: Types::JSON)]
    private array $blocks;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'translations')]
        #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Page $page,
        #[ORM\Column(type: Types::STRING, length: 35)]
        private string $locale,
        PageContent $content,
        string $path,
        DateTimeImmutable $now,
    ) {
        if (!LocaleCode::isValid($locale)) {
            throw new PageException(sprintf('"%s" is not a locale code such as "en-GB".', $locale));
        }
        $this->rewrite($content, $path, $now);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function page(): Page
    {
        return $this->page;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * The full address without the leading slash and locale prefix, for example `about/team`.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<array{type: string, version: int, data: array<string, mixed>}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function content(): PageContent
    {
        return new PageContent($this->title, $this->slug, $this->summary, $this->description, $this->blocks);
    }

    /**
     * @internal for {@see Page::write()}
     */
    public function rewrite(PageContent $content, string $path, DateTimeImmutable $now): void
    {
        self::checkPath($path, $content->slug);
        $this->title = $content->title;
        $this->slug = $content->slug;
        $this->path = $path;
        $this->summary = $content->summary;
        $this->description = $content->description;
        $this->blocks = $content->blocks;
        $this->updatedAt = $now;
    }

    /**
     * Changes the start of the address after a page above this one moved or got a new slug.
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function rebase(string $oldPrefix, string $newPrefix): void
    {
        if (!str_starts_with($this->path, $oldPrefix . '/')) {
            throw new PageException(sprintf('The address "%s" is not below "%s".', $this->path, $oldPrefix));
        }
        $path = $newPrefix . substr($this->path, strlen($oldPrefix));
        self::checkPath($path, $this->slug);
        $this->path = $path;
    }

    /**
     * @return array{title: string, slug: string, path: string, summary: ?string, description: ?string, blocks: list<array{type: string, version: int, data: array<string, mixed>}>}
     */
    public function snapshot(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => $this->path,
            'summary' => $this->summary,
            'description' => $this->description,
            'blocks' => $this->blocks,
        ];
    }

    private static function checkPath(string $path, string $slug): void
    {
        if (strlen($path) > self::PATH_MAX_LENGTH) {
            throw new PageException(sprintf('The full address must be %d characters or fewer.', self::PATH_MAX_LENGTH));
        }
        if ($path !== $slug && !str_ends_with($path, '/' . $slug)) {
            throw new PageException(sprintf('The address "%s" must end with the slug "%s".', $path, $slug));
        }
    }
}
