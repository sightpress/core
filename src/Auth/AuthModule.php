<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Auth\Command\CreateAdminCommand;
use SightPress\Core\Auth\Contract\DomainRegistration;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Auth\Registration\TenantDomainRegistration;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\Http\ChoosePasswordHandler;
use SightPress\Core\Auth\Http\ChangePasswordHandler;
use SightPress\Core\Auth\Http\ChangeEmailHandler;
use SightPress\Core\Auth\Http\ChangeEmailCodeHandler;
use SightPress\Core\Auth\Http\ChangePhoneCodeHandler;
use SightPress\Core\Auth\Http\ChangePhoneHandler;
use SightPress\Core\Auth\Account\PhoneChange;
use SightPress\Core\Auth\PhonePrompt;
use SightPress\Core\Auth\Account\EmailChange;
use SightPress\Core\Auth\Http\ForgotPasswordHandler;
use SightPress\Core\Auth\Http\LoginHandler;
use SightPress\Core\Auth\Http\LogoutHandler;
use SightPress\Core\Auth\Http\RegisterCodeHandler;
use SightPress\Core\Auth\Http\RegisterHandler;
use SightPress\Core\Auth\Http\ResetCodeHandler;
use SightPress\Core\Auth\Http\SetupAccountHandler;
use SightPress\Core\Auth\Provisioning\DemoAccounts;
use SightPress\Core\Auth\Provisioning\FirstSetupCode;
use SightPress\Core\Auth\Registration\ForgetUnconfirmedAccounts;
use SightPress\Core\Auth\Registration\RegistrationMailer;
use SightPress\Core\Auth\Registration\SelfRegistration;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Security\Authenticator;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

/**
 * Signing in and out: the shared `/login` and `/logout` pages, "keep me signed in" tokens, the {@see Authenticator}
 * that tells every request who is making it, and six-digit verification codes ({@see VerificationCodes}, public):
 * "Forgot your password?" and the first admin's setup code are typed on a page, never sent as links, and the
 * site's password policy ({@see PasswordPolicy}: settings `auth.password_*`). Also self-registration
 * ({@see SignUp}: `/register`, off by default, confirmed with an emailed code; unconfirmed accounts are removed by
 * `schedule:run` after a week) and the {@see EmailPolicy} for who may join. And, from *My account*, changing the
 * password (`/account/password`) and the email address (`/account/email`, confirmed with a code). Always on.
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
        $services->factory(VerificationCodes::class, static fn (ContainerInterface $c): VerificationCodes => new VerificationCodeStore(
            Services::get($c, Connection::class),
            Services::get($c, ClockInterface::class),
            Services::get($c, RateLimiters::class),
            Services::get($c, TenantConfig::class)->secret
                ?? throw new \LogicException('Verification codes need APP_SECRET: set it in the environment.'),
        ), public: true);
        $services->autowire(EvenTiming::class, public: true);
        $services->autowire(SessionAuthenticator::class, Authenticator::class);
        $services->autowire(PasswordRules::class, Passwords::class, public: true);
        $services->factory(EmailPolicy::class, static function (ContainerInterface $c): EmailPolicy {
            $policies = Services::get($c, TenantConfig::class)->policies;

            return $policies->emailDomains === null ? new AnyEmailPolicy() : new DomainsEmailPolicy($policies);
        }, public: true);
        $services->autowire(TenantDomainRegistration::class, DomainRegistration::class, public: true);
        foreach ([AuthPages::class, LoginHandler::class, LogoutHandler::class, ForgotPasswordHandler::class, ResetCodeHandler::class, SetupAccountHandler::class, ChoosePasswordHandler::class, SelfRegistration::class, RegistrationMailer::class, RegisterHandler::class, RegisterCodeHandler::class, ForgetUnconfirmedAccounts::class, EmailChange::class, ChangePasswordHandler::class, ChangeEmailHandler::class, ChangeEmailCodeHandler::class, PhoneChange::class, PhonePrompt::class, ChangePhoneHandler::class, ChangePhoneCodeHandler::class] as $class) {
            $services->autowire($class);
        }

        $settings = $registrar->settings();
        $settings->add(new Setting(
            PasswordPolicy::MIN_LENGTH_SETTING,
            'Shortest password allowed (number of characters)',
            SettingType::Integer,
            PasswordPolicy::MIN_LENGTH,
            sprintf('From %d to %d. %d or more is recommended.', PasswordPolicy::FLOOR, PasswordPolicy::MAX_LENGTH, PasswordPolicy::MIN_LENGTH),
        ));
        $settings->add(new Setting(PasswordPolicy::UPPER_SETTING, 'Must include a capital letter', SettingType::Boolean, true));
        $settings->add(new Setting(PasswordPolicy::LOWER_SETTING, 'Must include a lower-case letter', SettingType::Boolean, true));
        $settings->add(new Setting(PasswordPolicy::DIGIT_SETTING, 'Must include a number', SettingType::Boolean, true));
        $settings->add(new Setting(PasswordPolicy::SYMBOL_SETTING, 'Must include a symbol (such as ! ? # or %)', SettingType::Boolean, true));

        $registrar->subscribers()->listen(SiteProvisioning::class, FirstSetupCode::class, SiteProvisioning::SIGN_IN);
        $registrar->subscribers()->listen(SiteProvisioning::class, DemoAccounts::class, DemoAccounts::PRIORITY);
        $registrar->subscribers()->listen(ScheduleDue::class, ForgetUnconfirmedAccounts::class);
        $registrar->commands()->add(CreateAdminCommand::class);

        $routes = $registrar->routes();
        $routes->add(new Route('auth.login', '/login', LoginHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.logout', '/logout', LogoutHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.forgot', ForgotPasswordHandler::PATH, ForgotPasswordHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.forgot.code', ResetCodeHandler::PATH, ResetCodeHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.setup', SetupAccountHandler::PATH, SetupAccountHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.password', ChoosePasswordHandler::PATH, ChoosePasswordHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.register', SignUp::PATH, RegisterHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.register.code', SignUp::CODE_PATH, RegisterCodeHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.account.password', ChangePasswordHandler::PATH, ChangePasswordHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.account.email', ChangeEmailHandler::PATH, ChangeEmailHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.account.email.code', ChangeEmailCodeHandler::PATH, ChangeEmailCodeHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.account.phone', ChangePhoneHandler::PATH, ChangePhoneHandler::class, ['GET', 'POST']));
        $routes->add(new Route('auth.account.phone.code', ChangePhoneCodeHandler::PATH, ChangePhoneCodeHandler::class, ['GET', 'POST']));
    }
}
