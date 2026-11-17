<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Auth\AuthMigrations;
use SightPress\Core\Auth\AuthModule;
use SightPress\Core\Auth\Command\CreateAdminCommand;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Migrations\Version20261114130000;
use SightPress\Core\Auth\Migrations\Version20261117090000;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SessionAuthenticator;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Security\Authenticator;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(AuthModule::class)]
#[CoversClass(AuthMigrations::class)]
#[CoversClass(Version20261114130000::class)]
#[CoversClass(Version20261117090000::class)]
#[UsesNamespace('SightPress\Core')]
final class AuthModuleTest extends TestCase
{
    public function testIsRequiredAndNeedsSitesUsersAndRoles(): void
    {
        $manifest = new AuthModule()->manifest();

        self::assertSame('auth', $manifest->id);
        self::assertTrue($manifest->required);
        self::assertSame(['site', 'user', 'rbac'], array_map(static fn ($r) => $r->moduleId, $manifest->requires));
    }

    public function testRegistersTheSignInPagesTemplatesAndTheAuthenticator(): void
    {
        $registry = new AuthFixture()->database->registry;

        $routes = [];
        foreach ($registry->routes() as $registered) {
            if ($registered->module !== 'auth') {
                continue;
            }
            $routes[$registered->item->name] = $registered->item->path . ' ' . implode(',', $registered->item->methods);
        }
        self::assertSame([
            'auth.login' => '/login GET,POST',
            'auth.logout' => '/logout GET,POST',
            'auth.forgot' => '/login/forgot GET,POST',
            'auth.forgot.code' => '/login/forgot/code GET,POST',
            'auth.setup' => '/login/setup GET,POST',
            'auth.password' => '/login/password GET,POST',
        ], $routes);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'auth'));
        self::assertSame('auth', $views[0]->item->namespace);
        self::assertFileExists($views[0]->item->directory . '/login.html.twig');
        self::assertSame(SessionAuthenticator::class, $registry->services()[Authenticator::class]->item->class);
        $listeners = array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'auth'));
        self::assertSame(\SightPress\Core\Auth\Provisioning\FirstSetupCode::class, $listeners[0]->item->listener);
        self::assertSame(\SightPress\Core\Site\Contract\SiteProvisioning::SIGN_IN, $listeners[0]->item->priority);
        $commands = array_values(array_filter($registry->commands(), static fn ($c) => $c->module === 'auth'));
        self::assertSame([CreateAdminCommand::class], array_map(static fn ($c) => $c->item, $commands));
        self::assertTrue($registry->services()[VerificationCodes::class]->item->public);
    }

    public function testCodesAreKeyedWithTheTenantSecret(): void
    {
        $auth = new AuthFixture();
        $factory = $auth->database->registry->services()[VerificationCodes::class]->item->factory;
        self::assertNotNull($factory);
        $container = static fn (?string $secret) => new ContainerBuilder()->addDefinitions([
            Connection::class => $auth->database->connection,
            RateLimiters::class => $auth->limiters,
            ClockInterface::class => $auth->clock,
            TenantConfig::class => TenantConfig::fromArray([], '/project', $secret === null ? [] : ['APP_SECRET' => $secret]),
        ])->build();

        $codes = $factory($container(SiteFixture::SECRET));
        self::assertInstanceOf(VerificationCodeStore::class, $codes);
        $code = $codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', 7);
        self::assertTrue($auth->codes()->check(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', $code->code, '192.0.2.1')->isValid());

        $this->expectExceptionMessage('Verification codes need APP_SECRET');
        $factory($container(null));
    }

    public function testTheMigrationCreatesAndDropsTheTokenTable(): void
    {
        $database = new AuthFixture()->database;

        self::assertContains(RememberTokens::TABLE, $database->tables());
        $descriptions = array_map(
            static fn ($migration) => $migration->getMigration()->getDescription(),
            $database->dependencies()->getMigrationRepository()->getMigrations()->getItems(),
        );
        self::assertContains('Create the auth_remember_tokens table.', $descriptions);
        self::assertContains('Create the verification_code table.', $descriptions);
        self::assertContains('verification_code', $database->tables());

        $database->migrate('first');
        self::assertNotContains(RememberTokens::TABLE, $database->tables());
        self::assertNotContains('verification_code', $database->tables());
    }
}
