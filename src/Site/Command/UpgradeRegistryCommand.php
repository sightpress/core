<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tenant\SiteRegistryUpgrader;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `site:registry:upgrade`: moves `config/sites.php` to the format version this SightPress reads.
 */
final readonly class UpgradeRegistryCommand implements Command
{
    public function __construct(
        private SiteRegistryUpgrader $upgrader,
        private SiteRegistryFile $file,
    ) {
    }

    public function name(): string
    {
        return 'site:registry:upgrade';
    }

    public function description(): string
    {
        return 'Upgrades the site registry (config/sites.php) to the format this version of SightPress reads.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        try {
            [$from, $to] = $this->upgrader->upgrade();
            if ($from === $to) {
                $output->line(sprintf('The site registry already uses format version %d. Nothing to do.', $to));

                return self::SUCCESS;
            }

            $output->line(sprintf(
                'Upgraded the site registry from format version %d to %d. The previous file is kept as %s.bak.',
                $from,
                $to,
                $this->file->path,
            ));
        } catch (SiteRegistryException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->line('If PHP-FPM runs with opcache.validate_timestamps=0, reload it so the website reads the new file.');

        return self::SUCCESS;
    }
}
