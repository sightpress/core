<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use League\Flysystem\FilesystemOperator;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\LocaleRemoved;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Avatar\AvatarGenerator;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Contract\AccountDirectory;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\User\Contract\AccountRegistration;
use SightPress\Core\User\Contract\PendingAccounts;
use SightPress\Core\User\Contract\AccountRoster;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Http\AccountHandler;
use SightPress\Core\User\Http\AccountLanguageHandler;
use SightPress\Core\User\Http\AccountPages;
use SightPress\Core\User\Http\AccountPictureHandler;
use SightPress\Core\User\Http\AvatarFileHandler;
use SightPress\Core\User\Provisioning\CreateFirstAdmin;
use SightPress\Module\Access\Permission;
use SightPress\Module\Routing\Route;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * People with an account on a site: the `users` table, the repository, the first admin's account on a new site,
 * avatars (generated initials or an uploaded picture, served from `/avatars/`) and the *My account* page
 * (`/account`) where everyone, members included, changes their names, picture, time zone and language. Always on.
 */
final class UserModule implements ModuleInterface
{
    public const string ID = 'user';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', [new ModuleRequirement('site', '^0.1')], required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new UserMigrations());
        $registrar->services()->autowire(UserRepository::class, public: true);
        $registrar->services()->autowire(UserRepository::class, Accounts::class, public: true);
        $registrar->services()->autowire(UserRepository::class, AccountDirectory::class, public: true);
        $registrar->services()->autowire(UserRepository::class, AccountPreferences::class, public: true);
        $registrar->services()->autowire(AccountRegistrar::class, AccountRegistration::class, public: true);
        $registrar->services()->autowire(AccountRegistrar::class, PendingAccounts::class, public: true);
        $registrar->services()->autowire(AccountAdministration::class, AccountRoster::class, public: true);
        $registrar->services()->autowire(AvatarGenerator::class);
        $registrar->services()->factory(AvatarService::class, static fn (ContainerInterface $c): AvatarService => new AvatarService(
            Services::get($c, AvatarGenerator::class),
            new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: false, strip: true),
            Services::get($c, FilesystemOperator::class),
            Services::get($c, ClockInterface::class),
        ));
        foreach ([AccountPages::class, AccountHandler::class, AccountPictureHandler::class, AvatarFileHandler::class, AccountLanguageHandler::class] as $class) {
            $registrar->services()->autowire($class);
        }
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/user');
        $routes = $registrar->routes();
        $routes->add(new Route('user.account', AccountPages::PATH, AccountHandler::class, ['GET', 'POST']));
        $routes->add(new Route('user.account.picture', '/account/picture', AccountPictureHandler::class, ['POST']));
        $routes->add(new Route('user.account.language', AccountLanguageHandler::PATH, AccountLanguageHandler::class, ['POST']));
        $routes->add(new Route('user.account.picture.remove', AccountPictureHandler::REMOVE_PATH, AccountPictureHandler::class, ['POST']));
        $routes->add(new Route(
            'user.avatar',
            '/avatars/{' . AvatarFileHandler::FILE_ATTRIBUTE . '}',
            AvatarFileHandler::class,
            ['GET', 'HEAD'],
        ));
        $registrar->subscribers()->listen(SiteProvisioning::class, CreateFirstAdmin::class, SiteProvisioning::ACCOUNTS);
        $registrar->services()->autowire(ForgetRemovedLocale::class);
        $registrar->subscribers()->listen(LocaleRemoved::class, ForgetRemovedLocale::class);
        $registrar->permissions()->add(new Permission(
            'user.invite',
            'Invite people',
            'Invite people to the site with a role they are allowed to give.',
            ['admin', 'editor'],
        ));
        $registrar->permissions()->add(new Permission(
            'user.manage',
            'Manage users',
            'Change the role of any user, and deactivate accounts.',
            ['admin'],
        ));
    }
}
