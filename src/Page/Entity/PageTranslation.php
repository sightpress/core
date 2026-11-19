<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Entity;

use SightPress\Core\Template\Message;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\LocaleCode;

/**
 * A page written in one locale: its title, slug, full address, summary, description and blocks.
 *
 * The address is unique per locale, which also keeps every slug unique among the children of one parent.
 *
 * A translation can be a draft of its own: added to a page that is already written in another language, it isn't
 * shown to visitors (nor listed in the sitemap, `llms.txt` or menus) until someone publishes it, even when its page
 * is published ({@see isPublished()}).
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

    #[ORM\Column(name: 'social_image_id', type: Types::INTEGER, nullable: true)]
    private ?int $socialImage = null;

    #[ORM\Column(name: 'seo_title', type: Types::STRING, length: 200, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $noindex = false;

    #[ORM\Column(name: 'canonical_url', type: Types::STRING, length: 2000, nullable: true)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(name: 'exclude_from_site_search', type: Types::BOOLEAN)]
    private bool $excludeFromSiteSearch = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $draft = false;

    #[ORM\Column(name: 'machine_drafted', type: Types::BOOLEAN)]
    private bool $machineDrafted = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'translations')]
        #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Page $page,
        #[ORM\Column(type: Types::STRING, length: 35)]
        private string $locale,
        PageContent $content,
        string $path,
        DateTimeImmutable $now,
        bool $draft = false,
    ) {
        if (!LocaleCode::isValid($locale)) {
            throw new PageException(new Message('"{locale}" is not a locale code such as "en-GB".', ['locale' => $locale]));
        }
        $this->draft = $draft;
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

    /**
     * Media id of the page's own share image (`og:image`), if it has one.
     */
    public function socialImage(): ?int
    {
        return $this->socialImage;
    }

    public function seoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    public function canonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    /**
     * Whether the site's own search leaves this translation out.
     */
    public function isExcludedFromSiteSearch(): bool
    {
        return $this->excludeFromSiteSearch;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Whether this translation waits to be published on its own.
     */
    public function isDraft(): bool
    {
        return $this->draft;
    }

    /**
     * Whether the AI assistant drafted it and no person has edited it or said they checked it yet (A-6): until then it
     * can't be published.
     */
    public function isMachineDrafted(): bool
    {
        return $this->machineDrafted;
    }

    /**
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function setMachineDrafted(bool $machineDrafted): void
    {
        $this->machineDrafted = $machineDrafted;
    }

    /**
     * Whether visitors can read it: the page is published and this translation isn't a draft.
     */
    public function isPublished(): bool
    {
        return !$this->draft && $this->page->state() === PageState::Published;
    }

    /**
     * Publishes the translation (when the page is published) or makes it a draft again.
     *
     * @internal for {@see \SightPress\Core\Page\PageEditor}
     */
    public function setDraft(bool $draft, DateTimeImmutable $now): void
    {
        $this->draft = $draft;
        $this->updatedAt = $now;
    }

    public function content(): PageContent
    {
        return new PageContent($this->title, $this->slug, $this->summary, $this->description, $this->blocks, $this->socialImage, $this->seoTitle, $this->noindex, $this->canonicalUrl, $this->excludeFromSiteSearch);
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
        $this->socialImage = $content->socialImage;
        $this->seoTitle = $content->seoTitle;
        $this->noindex = $content->noindex;
        $this->canonicalUrl = $content->canonicalUrl;
        $this->excludeFromSiteSearch = $content->excludeFromSiteSearch;
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
            throw new PageException(new Message('The address "{path}" is not below "{old_prefix}".', ['path' => $this->path, 'old_prefix' => $oldPrefix]));
        }
        $path = $newPrefix . substr($this->path, strlen($oldPrefix));
        self::checkPath($path, $this->slug);
        $this->path = $path;
    }

    /**
     * @return array{title: string, slug: string, path: string, summary: ?string, description: ?string, blocks: list<array{type: string, version: int, data: array<string, mixed>}>, social_image: ?int, seo_title: ?string, noindex: bool, canonical_url: ?string, exclude_from_site_search: bool, draft: bool}
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
            'social_image' => $this->socialImage,
            'seo_title' => $this->seoTitle,
            'noindex' => $this->noindex,
            'canonical_url' => $this->canonicalUrl,
            'exclude_from_site_search' => $this->excludeFromSiteSearch,
            'draft' => $this->draft,
            'machine_drafted' => $this->machineDrafted,
        ];
    }

    private static function checkPath(string $path, string $slug): void
    {
        if (strlen($path) > self::PATH_MAX_LENGTH) {
            throw new PageException(new Message('The full address must be {max} characters or fewer.', ['max' => (string) self::PATH_MAX_LENGTH]));
        }
        if ($path !== $slug && !str_ends_with($path, '/' . $slug)) {
            throw new PageException(new Message('The address "{path}" must end with the slug "{slug}".', ['path' => $path, 'slug' => $slug]));
        }
    }
}
