<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Site\Contract\SiteLanguages;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

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

    public function parameters(): array
    {
        return [
            ...parent::parameters(),
            new CommandParameter('starter-pages', 'Also write the starter pages in the new language, as drafts to translate and publish.', CommandParameterType::Flag),
        ];
    }

    protected function change(SiteLanguages $languages, string $locale, CommandInput $input): array
    {
        $added = $languages->add($locale);

        return [true, [sprintf('Added %s. Its pages are at addresses starting with /%s.', self::language($added), strtolower($added))]];
    }

    protected function after(ContainerInterface $services, string $locale, CommandInput $input): array
    {
        if (!$input->flag('starter-pages')) {
            return [];
        }
        $locale = LocaleCode::normalise($locale);
        $report = Services::get($services, StarterContent::class)->writeIn($locale, null);
        $lines = [$report->titles === []
            ? sprintf('The starter pages are already written in %s.', self::language($locale))
            : sprintf('Wrote the starter pages in %s as drafts: %s. Publish each translation when it is ready.', self::language($locale), implode(', ', $report->titles))];
        if ($report->fallbacks !== []) {
            $lines[] = sprintf('Warning: there are no starter pages in %s yet, so they are in %s. Translate them before you publish them.', self::language($locale), self::language(StarterContent::LOCALES[0]));
        }

        return $lines;
    }
}
