<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Site\Contract\SiteLanguageException;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:locale:add|remove|default <slug> <locale>`: changes the languages of a site, as its admins can in the admin
 * panel ({@see SiteLanguages}); the registry is updated to match, so `site:list` shows them too. Each change is
 * written to the `sites` log and to the site's audit log.
 */
abstract readonly class SiteLocaleCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    /**
     * Makes the change and returns whether anything changed, and what to tell the person.
     *
     * @return array{bool, list<string>}
     *
     * @throws SiteLanguageException
     */
    abstract protected function change(SiteLanguages $languages, string $locale, CommandInput $input): array;

    /**
     * What the change is, for the log: `added`, `removed` or `made the default`.
     */
    abstract protected function verb(): string;

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('locale', 'Language, for example pt-PT for European Portuguese or es for Spanish:', required: true, label: 'Language'),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $languages = Services::get($this->containers->forSite($site), SiteLanguages::class);
        $locale = trim((string) $input->argument('locale'));
        try {
            [$changed, $lines] = $this->change($languages, $locale, $input);
        } catch (SiteLanguageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        if (!$changed) {
            foreach ($lines as $line) {
                $output->line($line);
            }

            return self::SUCCESS;
        }

        $this->sites->save($site->withLocales($languages->locales(), $languages->defaultLocale()));
        $this->containers->evict($slug);
        $this->loggers->create('sites')->notice(
            sprintf('Site "%s" language %s %s.', $slug, $locale, $this->verb()),
            ['site' => $slug, 'locale' => $locale, 'locales' => $languages->locales(), 'default_locale' => $languages->defaultLocale()],
        );
        foreach ($lines as $line) {
            $output->line($line);
        }
        $output->line(sprintf('The site "%s" is written in: %s.', $slug, self::list($languages)));

        return self::SUCCESS;
    }

    /**
     * The languages in words, the default first: "British English (en-GB, main), Spanish (es)".
     */
    protected static function list(SiteLanguages $languages): string
    {
        return implode(', ', array_map(
            static fn (string $locale): string => sprintf('%s (%s%s)', LocaleCode::describe($locale), $locale, $locale === $languages->defaultLocale() ? ', main' : ''),
            $languages->locales(),
        ));
    }

    protected static function language(string $locale): string
    {
        return sprintf('%s (%s)', LocaleCode::describe($locale), $locale);
    }
}
