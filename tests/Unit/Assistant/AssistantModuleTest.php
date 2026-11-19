<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantMigrations;
use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Assistant\Command\AskAssistantCommand;
use SightPress\Core\Assistant\Command\AssistantUsageCommand;
use SightPress\Core\Assistant\Command\DisableAssistantCommand;
use SightPress\Core\Assistant\Command\SetAssistantCommand;
use SightPress\Core\Assistant\Command\TestAssistantCommand;
use SightPress\Core\Assistant\Http\HostResolver;
use SightPress\Core\Assistant\Migrations\Version20261120090000;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Assistant\Provider\MistralProvider;
use SightPress\Core\Assistant\Provider\OpenAiCompatibleProvider;
use SightPress\Core\Assistant\Provider\OpenAiProvider;
use SightPress\Core\Assistant\Task\AltTextTask;
use SightPress\Core\Assistant\Task\AskTask;
use SightPress\Core\Assistant\Task\DraftPageTask;
use SightPress\Core\Assistant\Task\ExplainCheckTask;
use SightPress\Core\Assistant\Task\HelpTask;
use SightPress\Core\Assistant\Task\RewriteTask;
use SightPress\Core\Assistant\Task\SeoMetaTask;
use SightPress\Core\Assistant\Task\SummaryTask;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

#[CoversClass(AssistantModule::class)]
#[CoversClass(AssistantMigrations::class)]
#[CoversClass(Version20261120090000::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantModuleTest extends TestCase
{
    public function testIsOptionalOffByDefaultAndRegistersItsProvidersTasksPermissionsAndCommands(): void
    {
        $manifest = new AssistantModule()->manifest();
        self::assertSame(['assistant', false, false], [$manifest->id, $manifest->required, $manifest->enabledByDefault]);
        self::assertSame(['site', 'rbac', 'admin', 'page', 'block', 'media'], array_map(static fn ($requirement) => $requirement->moduleId, $manifest->requires));

        $candidates = array_values(array_filter(CoreModules::candidates(), static fn ($candidate) => $candidate->module instanceof AssistantModule));
        self::assertSame('AI assistant', $candidates[0]->about['assistant']->name);
        self::assertStringContainsString('your own key', $candidates[0]->about['assistant']->description);
        self::assertStringContainsString('are kept', $candidates[0]->about['assistant']->whenOff);

        $everything = new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates());
        self::assertNotContains('assistant', ModuleRegistry::register($everything->resolve(new ModuleSelection()))->modules(), 'off for new sites');
        $registry = ModuleRegistry::register($everything->resolve(new ModuleSelection(['assistant' => true])));
        self::assertTrue($registry->services()[Assistant::class]->item->public);
        self::assertSame(\SightPress\Core\Assistant\Http\SystemHostResolver::class, $registry->services()[HostResolver::class]->item->class);
        self::assertSame(
            [AnthropicProvider::class, OpenAiProvider::class, MistralProvider::class, OpenAiCompatibleProvider::class],
            array_map(static fn ($p) => $p->item, $registry->assistantProviders()),
        );
        self::assertSame([AskTask::class, SummaryTask::class, SeoMetaTask::class, RewriteTask::class, DraftPageTask::class, ExplainCheckTask::class, AltTextTask::class, HelpTask::class], array_map(static fn ($t) => $t->item, $registry->assistantTasks()));
        $permissions = array_values(array_filter($registry->permissions(), static fn ($p) => $p->module === 'assistant'));
        self::assertSame(
            [['assistant.use', ['admin', 'editor', 'contributor'], true], ['assistant.manage', ['admin'], false]],
            array_map(static fn ($p) => [$p->item->name, $p->item->defaultRoles, $p->item->allowedOnSignUp], $permissions),
        );
        self::assertSame(
            [SetAssistantCommand::class, DisableAssistantCommand::class, TestAssistantCommand::class, AssistantUsageCommand::class, AskAssistantCommand::class],
            array_values(array_map(static fn ($c) => $c->item, array_filter($registry->commands(), static fn ($c) => $c->module === 'assistant'))),
        );
        $migrations = new AssistantMigrations();
        self::assertSame(['SightPress\Core\Assistant\Migrations', true], [$migrations->namespace(), is_dir($migrations->directory())]);
    }

    public function testTheMigrationCreatesAndDropsTheUsageTable(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261120090000($connection, new \Psr\Log\NullLogger());
        $schema = new \Doctrine\DBAL\Schema\Schema();

        $migration->up($schema);
        self::assertSame('Create the assistant_usage table.', $migration->getDescription());
        self::assertSame(
            ['id', 'used_at', 'month', 'user_id', 'task', 'provider', 'model', 'input_tokens', 'output_tokens', 'outcome'],
            array_map(static fn ($column): string => $column->getObjectName()->toString(), $schema->getTable('assistant_usage')->getColumns()),
        );
        $migration->down($schema);
        self::assertFalse($schema->hasTable('assistant_usage'));
    }
}
