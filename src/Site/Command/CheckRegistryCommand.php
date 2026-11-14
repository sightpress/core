<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `site:registry:check`: validates `config/sites.php` and checks every site's database DSN can be decrypted with
 * this install's `APP_SECRET`, without starting any site.
 */
final readonly class CheckRegistryCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
    ) {
    }

    public function name(): string
    {
        return 'site:registry:check';
    }

    public function description(): string
    {
        return 'Checks the site registry (config/sites.php) and says what to fix.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        try {
            $sites = $this->sites->all();
        } catch (SiteRegistryException $exception) {
            $output->error('The site registry is not valid. ' . $exception->getMessage());

            return self::FAILURE;
        }

        $count = count($sites);
        if (!$this->secretBox->isConfigured()) {
            $output->line(sprintf('The site registry is valid: %s.', self::sites($count)));
            if ($count > 0) {
                $output->line('APP_SECRET is not set, so the database addresses were not checked.');
            }

            return self::SUCCESS;
        }

        $unreadable = [];
        foreach ($sites as $site) {
            try {
                $this->secretBox->decrypt($site->databaseDsn);
            } catch (SecretBoxException) {
                $unreadable[] = $site->slug;
            }
        }

        if ($unreadable !== []) {
            $output->error(sprintf(
                'The database address of %s "%s" can\'t be decrypted with this APP_SECRET. '
                . 'Use the APP_SECRET the address was saved with, or set the address again.',
                count($unreadable) === 1 ? 'site' : 'sites',
                implode('", "', $unreadable),
            ));

            return self::FAILURE;
        }

        $output->line(sprintf('The site registry is valid: %s.', self::sites($count)));

        return self::SUCCESS;
    }

    private static function sites(int $count): string
    {
        return match ($count) {
            0 => 'no sites yet',
            1 => '1 site',
            default => sprintf('%d sites', $count),
        };
    }
}
