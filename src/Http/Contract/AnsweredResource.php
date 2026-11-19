<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a public response showed, noted by the handler that answered it, for listeners of {@see RequestAnswered}
 * (analytics counts the pages read). Core puts one on every request of a site; a handler takes it with
 * {@see fromRequest()} and calls {@see page()} or {@see file()} when it answers with content.
 */
final class AnsweredResource
{
    /** A page of the site in HTML. */
    public const string PAGE = 'page';

    /** A page of the site as Markdown (`.md`, or `Accept: text/markdown`). */
    public const string MARKDOWN = 'markdown';

    private ?string $kind = null;

    private ?int $pageId = null;

    private ?string $locale = null;

    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $resource = $request->getAttribute(self::class);

        return $resource instanceof self ? $resource : null;
    }

    /**
     * The response shows a page in one of its languages, as HTML or as Markdown ({@see MARKDOWN}).
     */
    public function page(int $pageId, string $locale, bool $markdown = false): void
    {
        $this->kind = $markdown ? self::MARKDOWN : self::PAGE;
        $this->pageId = $pageId;
        $this->locale = $locale;
    }

    /**
     * The response is a file of the site that isn't a page, such as `sitemap` or `llms` (lower-case words).
     */
    public function file(string $kind): void
    {
        $this->kind = $kind;
        $this->pageId = null;
        $this->locale = null;
    }

    public function kind(): ?string
    {
        return $this->kind;
    }

    public function pageId(): ?int
    {
        return $this->pageId;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }
}
