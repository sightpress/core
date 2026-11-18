<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Module\Console\CommandInput;

/**
 * `site:locale:add <slug> <locale>`: the site is written in one more language. Translations written in it before it
 * was removed come back.
 */
final readonly class AddSiteLocaleCommand extends SiteLocaleCommand
{
    public function name(): string
    {
        return 'site:locale:add';
    }

    public function description(): string
    {
        return 'Adds a language to a site, so its pages can be translated into it.';
    }

    protected function verb(): string
    {
        return 'added';
    }

    protected function change(SiteLanguages $languages, string $locale, CommandInput $input): array
    {
        $added = $languages->add($locale);

        return [true, [sprintf('Added %s. Its pages are at addresses starting with /%s.', self::language($added), strtolower($added))]];
    }
}
