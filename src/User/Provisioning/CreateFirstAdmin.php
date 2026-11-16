<?php

declare(strict_types=1);

namespace SightPress\Core\User\Provisioning;

use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * New site, step {@see SiteProvisioning::ACCOUNTS}: creates the first admin's account with a generated avatar. It
 * is pending until they choose a password; their time zone is the site's.
 */
final readonly class CreateFirstAdmin
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function __invoke(SiteProvisioning $event): void
    {
        $admin = $event->admin;
        $user = new User($admin->email, $admin->firstName, $admin->lastName, $event->site->defaultTimezone, $this->clock->now());
        $users = Services::get($event->services, UserRepository::class);
        $users->save($user);
        Services::get($event->services, AvatarService::class)->generate($user);
        $users->save($user);

        $event->setAdminId((int) $user->id());
        $event->report(sprintf('Created the account of the first admin, %s (%s).', $user->fullName(), $user->email()));
    }
}
