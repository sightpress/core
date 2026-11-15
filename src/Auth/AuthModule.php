<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\Http\ForgotPasswordHandler;
use SightPress\Core\Auth\Http\LoginHandler;
use SightPress\Core\Auth\Http\LogoutHandler;
use SightPress\Core\Auth\Http\ResetPasswordHandler;
use SightPress\Core\Auth\Provisioning\FirstPasswordLink;
use SightPress\Core\Http\Security\Authenticator;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Signing in and out: the shared `/login` and `/logout` pages, "forgot your password?" links, "keep me signed in"
 * tokens and the {@see Authenticator} that tells every request who is making it. Always on.
 */
final class AuthModule implements ModuleInterface
{
    public const string ID = 'auth';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('user', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new AuthMigrations());
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/auth');

        $services = $registrar->services();
        foreach ([PasswordHasher::class, SignIn::class, RememberTokens::class, PasswordReset::class] as $class) {
            $services->autowire($class);
        }
        $services->factory(PasswordResetTokens::class, static fn (ContainerInterface $c): PasswordResetTokens => new PasswordResetTokens(
            Services::get($c, Accounts::class),
            Services::get($c, Site::class),
            Services::get($c, ClockInterface::class),
            Services::get($c, TenantConfig::class)->secret
                ?? throw new \LogicException('Password links need APP_SECRET: set it in the environment.'),
        ));
        $services->autowire(SessionAuthenticator::class, Authenticator::class);
        foreach ([AuthPages::class, LoginHandler::class, LogoutHandler::class, ForgotPasswordHandler::class, ResetPasswordHandler::class] as $class) {
            $services->autowire($class);
        }

        $registrar->subscribers()->listen(SiteProvisioning::class, FirstPasswordLink::class, SiteProvisioning::SIGN_IN);

        $routes = $registrar->routes();
        $routes->add(new Route('auth.login', '/login', LoginHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.logout', '/logout', LogoutHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.password.forgot', '/password/forgot', ForgotPasswordHandler::class, ['GET', 'POST']));
        $routes->add(new Route(
            'auth.password.reset',
            '/password/reset/{' . ResetPasswordHandler::TOKEN_ATTRIBUTE . '}',
            ResetPasswordHandler::class,
            ['GET', 'POST'],
        ));
    }
}
