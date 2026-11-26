<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Event\AccountChanged;

/**
 * `site:user:phone:reset <slug> <email>` (N-6, D35, gap 10): removes someone's mobile number, for example after they
 * lost their phone and nobody on the site can remove it, so they can add a new one. Works whatever two-step
 * confirmation says (the operator is trusted). Audited; the number is never printed.
 */
final readonly class ResetPhoneCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:user:phone:reset';
    }

    public function description(): string
    {
        return 'Removes the mobile number of an account (after a lost phone), so its owner can add a new one.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('email', 'Email address of the account:', required: true),
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
        $services = $this->containers->forSite($site);
        $email = trim((string) $input->argument('email'));
        $account = Services::get($services, Accounts::class)->findAccountByEmail($email);
        if ($account === null || $account->id() === null) {
            $output->error(sprintf('There is no account for %s on "%s".', $email, $slug));

            return self::FAILURE;
        }
        if (!Services::get($services, AccountPhones::class)->removePhone($account->id())) {
            $output->line(sprintf('%s has no mobile number on "%s".', $account->email(), $slug));

            return self::SUCCESS;
        }
        Services::get($services, EventDispatcherInterface::class)->dispatch(new AccountChanged($account->id(), null, ['phone']));
        $output->line(sprintf('The mobile number of %s is removed. They can add a new one on My account.', $account->email()));

        return self::SUCCESS;
    }
}
