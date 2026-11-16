<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

/**
 * What an editor writes for one locale of a page: the title, the slug, the summary, the description for search
 * engines and the blocks. Checked and tidied when it is made, so a page never holds content that breaks these rules.
 *
 * Blocks are stored as `{ "type": "…", "version": n, "data": { … } }` (ADR-0005); the {@see PageEditor} has the
 * block module check each block's data against its type when the page is saved.
 */
final readonly class PageContent
{
    public const int TITLE_MAX_LENGTH = 200;
    public const int SUMMARY_MAX_LENGTH = 300;
    public const int DESCRIPTION_MAX_LENGTH = 300;
    public const int MAX_BLOCKS = 500;
    public const int SEO_TITLE_MAX_LENGTH = 200;
    public const int CANONICAL_MAX_LENGTH = 2000;

    private const string BLOCK_TYPE_PATTERN = '/^(?:[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    public string $title;

    public string $slug;

    public ?string $summary;

    public ?string $description;

    /** @var list<array{type: string, version: int, data: array<string, mixed>}> */
    public array $blocks;

    /** Media id of the picture shared with links to this page (`og:image`), instead of the site's share image. */
    public ?int $socialImage;

    /** The title search engines show, when it should differ from the page title. */
    public ?string $seoTitle;

    /** Whether search engines are asked to leave the page out (it is then left out of the sitemap and llms.txt too). */
    public bool $noindex;

    /** The address of the original when this page copies another one (absolute, `https://` or `http://`). */
    public ?string $canonicalUrl;

    /**
     * @param string|null $slug        null makes one from the title ({@see Slug::fromText()})
     * @param list<mixed> $blocks
     * @param int|null    $socialImage  media id of the page's own share image
     * @param string|null $canonicalUrl the original's address, when this page copies another one
     */
    public function __construct(
        string $title,
        ?string $slug = null,
        ?string $summary = null,
        ?string $description = null,
        array $blocks = [],
        ?int $socialImage = null,
        ?string $seoTitle = null,
        bool $noindex = false,
        ?string $canonicalUrl = null,
    ) {
        $title = self::singleLine($title);
        if ($title === '') {
            throw new PageException('Give the page a title.');
        }
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw new PageException(sprintf('The title must be %d characters or fewer.', self::TITLE_MAX_LENGTH));
        }
        $slug = $slug === null || trim($slug) === '' ? Slug::fromText($title) : trim($slug);
        if (!Slug::isValid($slug)) {
            throw new PageException(sprintf(
                'The address "%s" can only use lower-case letters a to z, digits and single hyphens between them, and be %d characters or fewer.',
                $slug,
                Slug::MAX_LENGTH,
            ));
        }

        $this->title = $title;
        $this->slug = $slug;
        $this->summary = self::optional($summary, self::SUMMARY_MAX_LENGTH, 'The summary');
        $this->description = self::optional($description, self::DESCRIPTION_MAX_LENGTH, 'The search engine description');
        $this->blocks = self::blocks($blocks);
        if ($socialImage !== null && $socialImage < 1) {
            throw new PageException('The share image must be a file of the media library.');
        }
        $this->socialImage = $socialImage;
        $this->seoTitle = self::optional($seoTitle, self::SEO_TITLE_MAX_LENGTH, 'The title for search engines');
        $this->noindex = $noindex;
        $canonicalUrl = $canonicalUrl === null || trim($canonicalUrl) === '' ? null : trim($canonicalUrl);
        if ($canonicalUrl !== null && (strlen($canonicalUrl) > self::CANONICAL_MAX_LENGTH || preg_match('~^https?://[^\s/?#]+[^\s]*$~i', $canonicalUrl) !== 1)) {
            throw new PageException('The original address must be a full web address starting with https:// or http://.');
        }
        $this->canonicalUrl = $canonicalUrl;
    }

    /**
     * The same content with other blocks, for example after the block module checked and cleaned them.
     *
     * @param list<mixed> $blocks
     */
    public function withBlocks(array $blocks): self
    {
        return new self($this->title, $this->slug, $this->summary, $this->description, $blocks, $this->socialImage, $this->seoTitle, $this->noindex, $this->canonicalUrl);
    }

    /**
     * The content as stored in a revision snapshot.
     *
     * @return array{title: string, slug: string, summary: ?string, description: ?string, blocks: list<array{type: string, version: int, data: array<string, mixed>}>, social_image: ?int, seo_title: ?string, noindex: bool, canonical_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'blocks' => $this->blocks,
            'social_image' => $this->socialImage,
            'seo_title' => $this->seoTitle,
            'noindex' => $this->noindex,
            'canonical_url' => $this->canonicalUrl,
        ];
    }

    private static function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function optional(?string $text, int $max, string $what): ?string
    {
        $text = self::singleLine($text ?? '');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new PageException(sprintf('%s must be %d characters or fewer.', $what, $max));
        }

        return $text;
    }

    /**
     * @param list<mixed> $blocks
     *
     * @return list<array{type: string, version: int, data: array<string, mixed>}>
     */
    private static function blocks(array $blocks): array
    {
        if (count($blocks) > self::MAX_BLOCKS) {
            throw new PageException(sprintf('A page can have at most %d blocks.', self::MAX_BLOCKS));
        }

        $clean = [];
        foreach (array_values($blocks) as $index => $block) {
            if (
                !is_array($block)
                || !is_string($block['type'] ?? null)
                || preg_match(self::BLOCK_TYPE_PATTERN, $block['type']) !== 1
                || !is_int($block['version'] ?? null)
                || $block['version'] < 1
                || !is_array($block['data'] ?? null)
                || ($block['data'] !== [] && array_is_list($block['data']))
                || count($block) !== 3
            ) {
                throw new PageException(sprintf(
                    'Block %d is not a block: it needs a type, a version from 1 up and its data, and nothing else.',
                    $index + 1,
                ));
            }
            /** @var array<string, mixed> $data */
            $data = $block['data'];
            $clean[] = ['type' => $block['type'], 'version' => $block['version'], 'data' => $data];
        }

        return $clean;
    }
}
