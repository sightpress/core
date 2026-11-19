<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Core\Assistant\Admin\AssistantAskHandler;
use SightPress\Core\Assistant\Admin\AssistantInsertHandler;
use SightPress\Core\Assistant\Admin\AssistantReplies;
use SightPress\Core\Assistant\Admin\AssistantSettingsHandler;
use SightPress\Core\Assistant\Admin\AssistantTestHandler;
use SightPress\Core\Assistant\Admin\AssistantTranslateHandler;
use SightPress\Core\Assistant\Command\AskAssistantCommand;
use SightPress\Core\Assistant\Command\AssistantUsageCommand;
use SightPress\Core\Assistant\Command\DisableAssistantCommand;
use SightPress\Core\Assistant\Command\SetAssistantCommand;
use SightPress\Core\Assistant\Command\TestAssistantCommand;
use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Http\HostResolver;
use SightPress\Core\Assistant\Http\SystemHostResolver;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Assistant\Provider\MistralProvider;
use SightPress\Core\Assistant\Provider\OpenAiCompatibleProvider;
use SightPress\Core\Assistant\Provider\OpenAiProvider;
use SightPress\Core\Assistant\Provider\ProviderModels;
use SightPress\Core\Assistant\Task\AltTextTask;
use SightPress\Core\Assistant\Task\AskTask;
use SightPress\Core\Assistant\Task\DraftPageTask;
use SightPress\Core\Assistant\Task\ExplainCheckTask;
use SightPress\Core\Assistant\Task\HelpTask;
use SightPress\Core\Assistant\Help\HelpIndex;
use SightPress\Core\Assistant\Task\Prompts;
use SightPress\Core\Assistant\Task\RewriteTask;
use SightPress\Core\Assistant\Task\SeoMetaTask;
use SightPress\Core\Assistant\Task\SummaryTask;
use SightPress\Core\Assistant\Task\TranslateTask;
use SightPress\Core\Block\Contract\RichText;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The optional AI assistant (ADR-0010, ADR-0040, D13–D16): off by default, bring your own key. Core's providers
 * (Anthropic, OpenAI, Mistral and any OpenAI-compatible service) and tasks are registered like a module's would
 * be; {@see Assistant} runs a task for a person within their permissions, rate limit and the site's monthly budget,
 * and only ever returns a suggestion. Admins set it up on `/admin/assistant`; people ask on the dashboard and the page
 * editor (the panel and Suggest buttons, shown through `Infrastructure\Assistant\AssistantAccess`).
 * `site:assistant:set|disable|test|usage` and `assistant:ask` work from the console.
 */
final class AssistantModule implements ModuleInterface
{
    public const string ID = 'assistant';

    public const string USE = 'assistant.use';

    public const string MANAGE = 'assistant.manage';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('admin', '^0.1'), new ModuleRequirement('page', '^0.1'), new ModuleRequirement('block', '^0.1'), new ModuleRequirement('media', '^0.1')],
            enabledByDefault: false,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new AssistantMigrations());

        $services = $registrar->services();
        $services->autowire(SystemHostResolver::class, HostResolver::class);
        foreach ([
            EndpointGuard::class, AssistantHttp::class, ProviderModels::class,
            AssistantSettings::class, AssistantUsageStore::class, AssistantBudget::class,
        ] as $class) {
            $services->autowire($class);
        }
        foreach ([AssistantSetup::class, Assistant::class] as $class) {
            $services->autowire($class, public: true);
        }

        $permissions = $registrar->permissions();
        $permissions->add(new Permission(self::USE, 'Use the AI assistant', 'Ask the AI assistant for suggestions, which they check before using.', ['admin', 'editor', 'contributor'], allowedOnSignUp: true));
        $permissions->add(new Permission(self::MANAGE, 'Manage the AI assistant', 'Choose the AI service, its key, the monthly budget and who may use the assistant.', ['admin']));

        $assistant = $registrar->assistant();
        foreach ([AnthropicProvider::class, OpenAiProvider::class, MistralProvider::class, OpenAiCompatibleProvider::class] as $provider) {
            $services->autowire($provider);
            $assistant->addProvider($provider);
        }
        $services->autowire(Prompts::class);
        $services->autowire(HelpIndex::class);
        $services->autowire(RichText::class);
        foreach ([AskTask::class, SummaryTask::class, SeoMetaTask::class, RewriteTask::class, DraftPageTask::class, ExplainCheckTask::class, AltTextTask::class, HelpTask::class, TranslateTask::class] as $task) {
            $services->autowire($task);
            $assistant->addTask($task);
        }

        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/assistant');
        foreach ([AssistantReplies::class, AssistantSettingsHandler::class, AssistantTestHandler::class, AssistantAskHandler::class, AssistantInsertHandler::class, AssistantTranslateHandler::class] as $class) {
            $services->autowire($class);
        }
        $routes = $registrar->routes();
        $routes->add(new Route('assistant.settings', AssistantSettingsHandler::PATH, AssistantSettingsHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('assistant.test', AssistantTestHandler::PATH, AssistantTestHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('assistant.ask', AssistantAskHandler::PATH, AssistantAskHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('assistant.translate', AssistantTranslateHandler::PATH, AssistantTranslateHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('assistant.insert', AssistantInsertHandler::PATH, AssistantInsertHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('assistant.settings', 'AI assistant', 'assistant.settings', self::MANAGE, 'admin.settings', 20));

        foreach ([SetAssistantCommand::class, DisableAssistantCommand::class, TestAssistantCommand::class, AssistantUsageCommand::class, AskAssistantCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
