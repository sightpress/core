<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Sms\Command\DisableSmsCommand;
use SightPress\Core\Sms\Command\EnableSmsCommand;
use SightPress\Core\Sms\Command\RemoveSmsCommand;
use SightPress\Core\Sms\Command\SetSmsCommand;
use SightPress\Core\Sms\Command\TestSmsCommand;
use SightPress\Core\Sms\Migrations\Version20261125100000;
use SightPress\Core\Sms\Provider\TwilioProvider;
use SightPress\Core\Sms\Provider\VonageProvider;
use SightPress\Core\Sms\SmsMigrations;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Tenant\ModuleTurnOnCheck;

#[CoversClass(SmsModule::class)]
#[CoversClass(SmsMigrations::class)]
#[CoversClass(Version20261125100000::class)]
#[UsesNamespace('SightPress\Core')]
final class SmsModuleTest extends TestCase
{
    public function testIsOptionalOffByDefaultAndRegistersItsProvidersRouteAndCommands(): void
    {
        $manifest = new SmsModule()->manifest();
        self::assertSame(['sms', false, false], [$manifest->id, $manifest->required, $manifest->enabledByDefault]);
        self::assertSame(['site', 'rbac', 'admin', 'user'], array_map(static fn ($requirement) => $requirement->moduleId, $manifest->requires));

        $candidates = array_values(array_filter(CoreModules::candidates(), static fn ($candidate) => $candidate->module instanceof SmsModule));
        self::assertSame('Text messages', $candidates[0]->about['sms']->name);
        self::assertStringContainsString('are kept', $candidates[0]->about['sms']->whenOff);

        $everything = new ModuleLoader('0.5.0')->catalogue(CoreModules::candidates());
        self::assertNotContains('sms', ModuleRegistry::register($everything->resolve(new ModuleSelection()))->modules(), 'off for new sites');
        $registry = ModuleRegistry::register($everything->resolve(new ModuleSelection(['sms' => true])));
        self::assertTrue($registry->services()[SmsSender::class]->item->public);
        self::assertSame(SmsSetup::class, $registry->services()[ModuleTurnOnCheck::PREFIX . 'sms']->item->class);
        self::assertSame([TwilioProvider::class, VonageProvider::class], array_map(static fn ($provider) => $provider->item, $registry->smsProviders()));
        $routes = array_values(array_filter($registry->routes(), static fn ($route) => $route->module === 'sms'));
        self::assertSame([['sms.webhook', '/_sms/webhook', ['POST'], []]], array_map(static fn ($route) => [$route->item->name, $route->item->path, $route->item->methods, $route->item->middleware], $routes));
        $permissions = array_values(array_filter($registry->permissions(), static fn ($permission) => $permission->module === 'sms'));
        self::assertSame([['sms.manage', ['admin']]], array_map(static fn ($permission) => [$permission->item->name, $permission->item->defaultRoles], $permissions));
        self::assertSame(
            [SetSmsCommand::class, RemoveSmsCommand::class, EnableSmsCommand::class, DisableSmsCommand::class, TestSmsCommand::class],
            array_values(array_map(static fn ($command) => $command->item, array_filter($registry->commands(), static fn ($command) => $command->module === 'sms'))),
        );
        $migrations = new SmsMigrations();
        self::assertSame(['SightPress\Core\Sms\Migrations', true], [$migrations->namespace(), is_dir($migrations->directory())]);
    }

    public function testTheMigrationCreatesAndDropsItsTables(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261125100000($connection, new \Psr\Log\NullLogger());
        $schema = new \Doctrine\DBAL\Schema\Schema();

        $migration->up($schema);
        self::assertSame('Create the sms_message, sms_opt_out and sms_webhook_report tables.', $migration->getDescription());
        self::assertSame(
            ['id', 'created_at', 'month', 'purpose', 'to_masked', 'to_hash', 'user_id', 'provider', 'provider_message_id', 'parts', 'status', 'failure', 'body', 'sent_at', 'updated_at'],
            array_map(static fn ($column): string => $column->getObjectName()->toString(), $schema->getTable('sms_message')->getColumns()),
        );
        self::assertTrue($schema->hasTable('sms_opt_out') && $schema->hasTable('sms_webhook_report'));
        $migration->down($schema);
        self::assertFalse($schema->hasTable('sms_message') || $schema->hasTable('sms_opt_out') || $schema->hasTable('sms_webhook_report'));
    }
}
