<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Assistant;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * The assistant on a real site: off for new sites (no providers, no tasks, contacts nobody), its table made when an
 * admin turns it on, and its permissions given to the roles that should have them.
 */
#[CoversNothing]
final class AssistantModuleSwitchTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('assistant_switch');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testIsOffForNewSitesAndWorksOnceTurnedOn(): void
    {
        $site = $this->site->container();
        self::assertSame([], Services::get($site, AssistantExtensions::class)->providers());
        self::assertSame([], Services::get($site, AssistantExtensions::class)->tasks());

        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'assistant', 1);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        $site = $this->site->container();

        self::assertSame(['anthropic', 'openai', 'mistral', 'openai_compatible'], array_keys(Services::get($site, AssistantExtensions::class)->providers()));
        self::assertSame(['ask', 'summary', 'seo_meta', 'rewrite', 'draft_page', 'explain_check', 'alt_text', 'help', 'translate'], array_keys(Services::get($site, AssistantExtensions::class)->tasks()));
        self::assertTrue(Services::get($site, Connection::class)->createSchemaManager()->tablesExist([AssistantUsageStore::TABLE]));
        self::assertSame(0, Services::get($site, AssistantUsageStore::class)->tokens(gmdate('Y-m')));
        self::assertSame([], Services::get($site, Assistant::class)->tasksFor(null), 'nothing until a service is chosen');

        Services::get($site, AssistantSettings::class)->connect('anthropic', 'claude-haiku-5-5', null, 'sk-test-0123456789', 1);
        self::assertSame(['ask', 'summary', 'seo_meta', 'rewrite', 'draft_page', 'explain_check', 'alt_text', 'help', 'translate'], array_keys(Services::get($site, Assistant::class)->tasksFor(null)));

        $roles = Services::get($site, RoleRepository::class);
        $holders = [];
        foreach ($roles->roles() as $role) {
            $holders[$role->name()] = array_values(array_filter(
                array_map(static fn ($permission) => $permission->name(), $role->permissions()),
                static fn (string $name): bool => str_starts_with($name, 'assistant.'),
            ));
        }
        ksort($holders);
        self::assertSame(['admin' => ['assistant.manage', 'assistant.use'], 'contributor' => ['assistant.use'], 'editor' => ['assistant.use'], 'member' => []], array_map(static function (array $names): array {
            sort($names);

            return $names;
        }, $holders));
    }
}
