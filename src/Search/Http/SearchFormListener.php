<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Http;

use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Search\Contract\SearchPage;
use SightPress\Core\Tenant\Site;

/**
 * Puts the search form in the header of every page of the public site (`search_action`: where it searches, in the
 * page's language), while site search is on.
 */
final readonly class SearchFormListener
{
    public function __construct(private Site $site)
    {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        $locale = in_array($event->locale, $this->site->locales, true) ? $event->locale : $this->site->defaultLocale;
        $event->set('search_action', SearchPage::url($locale, $this->site->defaultLocale));
    }
}
