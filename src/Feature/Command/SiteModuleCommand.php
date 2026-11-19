<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Command;

use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteModuleException;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:module:list|enable|disable <slug> [<module>]`: the site's optional modules, as its admins see them on the
 * *Features* screen ({@see SiteModules}). Changes are written to the `sites` log and to the site's audit log.
 */
abstract readonly class SiteModuleCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteModules $modules,
        private LoggerFactory $loggers,
    ) {
    }

    /**
     * Makes the change (or reads the modules) and returns what to tell the person, and what to log (null: nothing).
     *
     * @return array{list<string>, string|null}
     *
     * @throws SiteModuleException
     */
    abstract protected function change(SiteModules $modules, Site $site, CommandInput $input): array;

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('module', 'Id of the module, as site:module:list shows it, for example acme.events:', required: true, label: 'Module'),
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

        try {
            [$lines, $log] = $this->change($this->modules, $site, $input);
        } catch (SiteModuleException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($log !== null) {
            $this->loggers->create('sites')->notice($log, ['site' => $slug, 'module' => $input->argument('module')]);
        }
        foreach ($lines as $line) {
            $output->line($line);
        }

        return self::SUCCESS;
    }

    protected static function module(CommandInput $input): string
    {
        return trim((string) $input->argument('module'));
    }
}
