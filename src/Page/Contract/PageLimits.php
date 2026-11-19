<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Publish\PublishChecker;

/**
 * How long a page's texts may be, for modules that suggest them (the AI assistant).
 */
final class PageLimits
{
    public const int TITLE = PageContent::TITLE_MAX_LENGTH;

    public const int SUMMARY = PageContent::SUMMARY_MAX_LENGTH;

    public const int SEO_TITLE = PageContent::SEO_TITLE_MAX_LENGTH;

    public const int DESCRIPTION = PageContent::DESCRIPTION_MAX_LENGTH;

    /** About how much of a description search engines show (the publish checks warn above it). */
    public const int DESCRIPTION_SHOWN = PublishChecker::DESCRIPTION_MAX;
}
