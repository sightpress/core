<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Command;

use InvalidArgumentException;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Provisioning\FirstSetupCode;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Contract\PendingAccounts;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:user:create-admin <slug>`: makes an admin account that waits for its password and prints a one-time setup
 * code (7 days) and the page where the person types it. Run it again for the same address while the account still
 * waits, and it prints a new code (the old one stops working). An account that already signs in is left alone: that
 * person uses "Forgot your password?".
 */
final readonly class CreateAdminCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:user:create-admin';
    }

    public function description(): string
    {
        return 'Creates an admin on a site and prints the setup code they choose their password with.';
    }

    public function parameters(): array
    {
        $option = CommandParameterType::Option;

        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('email', 'Email address of the admin:', $option, true),
            new CommandParameter('first-name', 'First name of the admin:', $option, true),
            new CommandParameter('last-name', 'Last name of the admin:', $option, true),
            new CommandParameter('url', 'Address of the site the printed page starts with (default: https:// and the first domain).', $option),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = $input->argument('slug') ?? '';
        $site = $this->sites->find($slug);
        if ($site === null) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        try {
            $admin = new FirstAdmin($input->option('email') ?? '', $input->option('first-name') ?? '', $input->option('last-name') ?? '');
        } catch (InvalidArgumentException $exception) {
            $output->error($exception->getMessage());

            return self::INVALID;
        }
        $url = trim($input->option('url') ?? '');
        $url = $url === '' ? 'https://' . $site->domains[0] : $url;

        try {
            $services = $this->containers->forSite($site);
            $account = Services::get($services, Accounts::class)->findAccountByEmail($admin->email);
            if ($account !== null && !$account->isPending()) {
                $output->error(sprintf(
                    'There is already an account for %s that can sign in. If they forgot their password, they choose '
                    . '"Forgot your password?" on the sign-in page.',
                    $admin->email,
                ));

                return self::FAILURE;
            }
            if ($account === null) {
                $account = Services::get($services, PendingAccounts::class)
                    ->createPending($admin->email, $admin->firstName, $admin->lastName, $site->defaultTimezone);
                $output->line(sprintf('Created the account of %s %s (%s).', $admin->firstName, $admin->lastName, $account->email()));
            } else {
                $output->line(sprintf('The account of %s is still waiting for its password: here is a new code.', $account->email()));
            }
            $id = (int) $account->id();
            Services::get($services, RoleGrants::class)->assign($id, 'admin');
            $output->line(FirstSetupCode::instructions(Services::get($services, VerificationCodes::class), $id, $account->email(), $url));
        } finally {
            $this->containers->evict($site->slug);
        }

        return self::SUCCESS;
    }
}
