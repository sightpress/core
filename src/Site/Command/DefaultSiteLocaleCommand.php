<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Module\Console\CommandInput;

/**
 * `site:locale:default <slug> <locale>`: makes one of the site's languages the main one. Its pages then have
 * addresses without a language part, and the old main language's pages get one.
 */
final readonly class DefaultSiteLocaleCommand extends SiteLocaleCommand
{
    public function name(): string
    {
        return 'site:locale:default';
    }

    public function description(): string
    {
        return 'Makes one of a site\'s languages its main language. The addresses of the pages in both languages change.';
    }

    protected function verb(): string
    {
        return 'made the default';
    }

    protected function change(SiteLanguages $languages, string $locale, CommandInput $input): array
    {
        $old = $languages->defaultLocale();
        if (!$languages->makeDefault($locale)) {
            return [false, [sprintf('%s is already the main language.', self::language($locale))]];
        }

        return [true, [
            sprintf('%s is now the main language: its pages are at addresses without a language part.', self::language($locale)),
            sprintf('Warning: the pages in %s moved to addresses starting with /%s. Links to their old addresses no longer work.', self::language($old), strtolower($old)),
        ]];
    }
}
