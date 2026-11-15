<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `site:list`: one sentence per site (no table, so it reads well with a screen reader), with its database named
 * without credentials.
 */
final readonly class ListSitesCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
    ) {
    }

    public function name(): string
    {
        return 'site:list';
    }

    public function description(): string
    {
        return 'Lists the sites with their status, domains, language and database.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $sites = $this->sites->all();
        if ($sites === []) {
            $output->line('There are no sites yet. Create one with site:create.');

            return self::SUCCESS;
        }

        $output->line(count($sites) === 1 ? '1 site:' : sprintf('%d sites:', count($sites)));
        foreach ($sites as $site) {
            $output->line(sprintf(
                '%s ("%s"): %s. Domains: %s. Language: %s. Time zone: %s. Database: %s.',
                $site->slug,
                $site->name,
                $site->status->value,
                implode(', ', $site->domains),
                implode(', ', $site->locales),
                $site->defaultTimezone,
                $this->database($site),
            ));
        }

        return self::SUCCESS;
    }

    private function database(Site $site): string
    {
        if (!$this->secretBox->isConfigured()) {
            return 'not shown, APP_SECRET is not set';
        }

        try {
            return DatabaseDsn::parse($this->secretBox->decrypt($site->databaseDsn))->describe();
        } catch (SecretBoxException | DatabaseDsnException) {
            return 'can\'t be read with this APP_SECRET';
        }
    }
}
