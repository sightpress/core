<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Block\Contract\BlockContexts;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Media\Contract\MediaFormats;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Block\BlockContext;

/**
 * Makes {@see SiteBlockContext}s for the current site. Pages in the default locale have no prefix; the others are
 * under their lower-case tag (ADR-0007).
 */
final readonly class SiteBlockContexts implements BlockContexts
{
    public function __construct(
        private Site $site,
        private MediaLibrary $media,
        private MediaUrlGenerator $urls,
        private DefaultBlockRenderer $renderer,
        private MediaFormats $formats,
    ) {
    }

    public function create(string $locale, string $baseUrl, ?ServerRequestInterface $request = null): BlockContext
    {
        if (!in_array($locale, $this->site->locales, true)) {
            throw new BlockException(sprintf('The site isn\'t written in "%s".', $locale));
        }

        return new SiteBlockContext(
            $locale,
            rtrim($baseUrl, '/'),
            $locale === $this->site->defaultLocale ? '' : '/' . strtolower($locale),
            $this->media,
            $this->urls,
            $this->renderer,
            $this->formats,
            $request,
        );
    }
}
