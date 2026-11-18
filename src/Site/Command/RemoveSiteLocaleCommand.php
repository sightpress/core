<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Site\Contract\SiteLanguageException;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:locale:remove <slug> <locale> --yes`: the site is no longer written in a language. Its translations are kept
 * but nobody can reach them; adding the language again brings them back. People who chose it get the site's main
 * language. Without `--yes` it only says what would happen.
 */
final readonly class RemoveSiteLocaleCommand extends SiteLocaleCommand
{
    public function name(): string
    {
        return 'site:locale:remove';
    }

    public function description(): string
    {
        return 'Removes a language from a site. Its translations are kept, but visitors can no longer reach them.';
    }

    public function parameters(): array
    {
        return [
            ...parent::parameters(),
            new CommandParameter('yes', 'Yes, remove the language. Its pages stop being reachable until it is added again.', CommandParameterType::Flag),
        ];
    }

    protected function verb(): string
    {
        return 'removed';
    }

    protected function change(SiteLanguages $languages, string $locale, CommandInput $input): array
    {
        $languages->checkRemovable($locale);
        if (!$input->flag('yes')) {
            throw new SiteLanguageException(sprintf(
                'Removing %s makes its translated pages unreachable (they are kept, and come back when the language is added again), and people who chose it get the main language. Run the command again with --yes to remove it.',
                self::language($locale),
            ));
        }
        $languages->remove($locale);

        return [true, [sprintf('Removed %s. Its translations are kept: add the language again to bring them back.', self::language($locale))]];
    }
}
