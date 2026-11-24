<?php

declare(strict_types=1);

namespace SightPress\Core\People\Command;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Rbac\Contract\RoleRefused;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:role:list|create|grant|revoke|delete <slug> …` (X-4): the same roles and rules as the *Roles* screen
 * ({@see Roles}). Changes are audited with the command line as the actor.
 */
abstract readonly class RoleCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    /**
     * Makes the change (or reads the roles) and returns what to tell the person.
     *
     * @return list<string>
     *
     * @throws RoleRefused
     */
    abstract protected function change(Roles $roles, CommandInput $input): array;

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('role', 'Name of the role, as site:role:list shows it, for example editor:', required: true, label: 'Role'),
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
            $lines = $this->change(Services::get($this->containers->forSite($site), Roles::class), $input);
        } catch (RoleRefused $refused) {
            foreach ($refused->problems as $problem) {
                $output->error((string) $problem);
            }

            return self::FAILURE;
        } finally {
            $this->containers->evict($site->slug);
        }
        foreach ($lines as $line) {
            $output->line($line);
        }

        return self::SUCCESS;
    }

    protected static function role(CommandInput $input): string
    {
        return trim((string) $input->argument('role'));
    }

    /**
     * @param list<string> $names
     */
    protected static function names(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', $names);
    }
}
