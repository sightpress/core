<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Admin\AssistantAsk;
use SightPress\Core\Assistant\Admin\AssistantAskHandler;
use SightPress\Core\Assistant\Admin\AssistantInsertHandler;
use SightPress\Core\Assistant\Admin\AssistantReplies;
use SightPress\Core\Assistant\Admin\AssistantSettingsHandler;
use SightPress\Core\Assistant\Admin\AssistantTestHandler;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Assistant\Contract\AssistantInsert;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\Contract\PageText;
use SightPress\Core\Page\Contract\PageTexts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageTextQuery;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\Modules\TestAssistantModule;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The AI assistant screen, the panel on the dashboard and the page editor, the Suggest buttons, the answers with and
 * without JavaScript and Insert, through the kernel with people of each role and the pretend "Test AI" service.
 */
#[CoversClass(AssistantSettingsHandler::class)]
#[CoversClass(AssistantTestHandler::class)]
#[CoversClass(AssistantAskHandler::class)]
#[CoversClass(AssistantInsertHandler::class)]
#[CoversClass(AssistantReplies::class)]
#[CoversClass(AssistantAsk::class)]
#[CoversClass(AssistantAccess::class)]
#[CoversClass(AssistantInsert::class)]
#[CoversClass(PageTextQuery::class)]
#[CoversClass(PageText::class)]
#[CoversClass(PageEditorHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantAdminTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private const string KEY = 'sk-test-canary-7e4a';

    private const array XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    private KernelSite $site;

    private int $pageId;

    protected function setUp(): void
    {
        $this->site = new KernelSite('assistant_admin', locales: ['en-GB', 'pt-PT'], extraModules: [new ModuleCandidate(new TestAssistantModule(), 'acme/test-ai')]);
        $page = Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Opening hours', 'hours', summary: 'When we are open.'));
        $this->pageId = (int) $page->id();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testWhileTheAssistantIsOffThereIsNoScreenPanelOrButton(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');

        self::assertSame(404, $ada->request('GET', AssistantSettingsHandler::PATH)->getStatusCode());
        self::assertSame(404, $ada->submit('/admin', ['question' => 'Hello?'], AssistantAskHandler::PATH)->getStatusCode());
        $dashboard = (string) $ada->request('GET', '/admin')->getBody();
        self::assertStringNotContainsString('Ask the assistant', $dashboard);
        self::assertStringNotContainsString('AI assistant', $dashboard, 'no menu entry');
        $editor = (string) $ada->request('GET', '/admin/pages/' . $this->pageId)->getBody();
        self::assertStringNotContainsString('Suggest a summary', $editor);
        self::assertStringNotContainsString('assistant.js', $editor);
    }

    public function testAnAdminSetsUpTheServiceKeyAndLimits(): void
    {
        $this->turnOn();
        $ada = $this->browser('ada@example.org', 'admin');

        $html = (string) $ada->request('GET', AssistantSettingsHandler::PATH)->getBody();
        self::assertStringContainsString('<title>AI assistant - Admin', $html);
        self::assertStringContainsString('Not ready yet. No AI service is chosen yet.', $html);
        self::assertStringContainsString('<label for="assistant-service-provider">Anthropic (Claude)</label>', $html);
        self::assertStringContainsString('A pretend AI service for tests. Nothing leaves the server.', $html);
        self::assertStringContainsString('<optgroup label="Test AI">', $html);
        self::assertStringContainsString('This site has no API key of its own.', $html);
        self::assertStringContainsString('<a href="/admin/assistant" aria-current="page">AI assistant</a>', $html, 'menu entry under the site settings');

        $missing = $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => '', 'model' => ''], AssistantSettingsHandler::PATH);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('Choose an AI service.', (string) $missing->getBody());
        $wrongModel = (string) $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => 'test_ai', 'model' => 'anthropic::claude-haiku-5-5'], AssistantSettingsHandler::PATH)->getBody();
        self::assertStringContainsString('Choose a model of Test AI.', $wrongModel);
        $custom = (string) $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => 'openai_compatible', 'custom_model' => '', 'base_url' => 'http://8.8.8.8/v1'], AssistantSettingsHandler::PATH)->getBody();
        self::assertStringContainsString('Enter the name of the model to use with Another OpenAI-compatible service.', $custom);
        self::assertStringContainsString('The assistant’s address must start with https://', $custom);
        $noAddress = (string) $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => 'openai_compatible', 'custom_model' => 'llama', 'base_url' => ''], AssistantSettingsHandler::PATH)->getBody();
        self::assertStringContainsString('Enter the address of the service, starting with https://.', $noAddress);

        $saved = $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => 'test_ai', 'model' => 'test_ai::test-small', 'base_url' => 'https://ignored.example', 'key' => self::KEY], AssistantSettingsHandler::PATH);
        self::assertSame(303, $saved->getStatusCode());
        $settings = $this->settings();
        self::assertSame(['test_ai', 'test-small', null, self::KEY], [$settings->provider(), $settings->model(), $settings->baseUrl(), $settings->key()]);
        $html = (string) $ada->request('GET', AssistantSettingsHandler::PATH)->getBody();
        self::assertStringContainsString('The AI service is saved.', $html);
        self::assertStringContainsString('Ready: people can ask Test AI (test-small).', $html);
        self::assertStringContainsString('Saved API key: ••••7e4a', $html);
        self::assertStringNotContainsString(self::KEY, $html, 'the key is never shown again');
        self::assertMatchesRegularExpression('#<option value="test_ai::test-small" selected>#', $html);

        $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'limits', 'roles' => ['editor', 'admin', 'nonsense'], 'budget' => '5000'], AssistantSettingsHandler::PATH);
        self::assertSame([5000, ['admin', 'editor']], [$this->settings()->budget(), $this->settings()->roles()]);
        $bad = $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'limits', 'budget' => 'lots'], AssistantSettingsHandler::PATH);
        self::assertStringContainsString('Enter the budget as a whole number of tokens', (string) $bad->getBody());
        $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'limits', 'roles' => 'editor', 'budget' => ''], AssistantSettingsHandler::PATH);
        self::assertSame([null, ['admin']], [$this->settings()->budget(), $this->settings()->roles()]);
        self::assertStringContainsString('Used this month: 0 tokens. There is no monthly limit.', (string) $ada->request('GET', AssistantSettingsHandler::PATH)->getBody());

        $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'remove_key'], AssistantSettingsHandler::PATH);
        self::assertStringContainsString('The API key is removed.', $this->page($ada, AssistantSettingsHandler::PATH));
        $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'remove_key'], AssistantSettingsHandler::PATH);
        self::assertStringContainsString('There was no key to remove.', $this->page($ada, AssistantSettingsHandler::PATH));
        self::assertSame(303, $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'other'], AssistantSettingsHandler::PATH)->getStatusCode());
    }

    public function testAnOrganisationThatChoosesForEverySiteOnlyShowsItsChoice(): void
    {
        $this->site->remove();
        $this->site = new KernelSite('assistant_admin_tenant', extraModules: [new ModuleCandidate(new TestAssistantModule(), 'acme/test-ai')], tenant: ['assistant' => ['provider' => 'test_ai', 'model' => 'test-small', 'site_keys' => false, 'monthly_budget' => 1000]], variables: ['ASSISTANT_API_KEY' => 'sk-tenant-1234']);
        $this->turnOn();
        $ada = $this->browser('ada@example.org', 'admin');

        $html = $this->page($ada, AssistantSettingsHandler::PATH);
        self::assertStringContainsString('Your organisation’s choice: Test AI (test-small). Sites that use it share your organisation’s key.', $html);
        self::assertStringContainsString('Your organisation chooses the AI service for every site', $html);
        self::assertStringContainsString('Ready: people can ask Test AI (test-small). Your organisation chose this service.', $html);
        self::assertStringContainsString('of 1,000 tokens (0%)', $html);
        self::assertStringNotContainsString('name="op" value="service"', $html);
        self::assertSame(303, $ada->submit(AssistantSettingsHandler::PATH, ['op' => 'service', 'provider' => 'anthropic'], AssistantSettingsHandler::PATH)->getStatusCode());
        self::assertNull($this->settings()->provider());
    }

    public function testTestConnectionSaysWhetherItWorks(): void
    {
        $this->turnOn();
        $ada = $this->browser('ada@example.org', 'admin');

        $notReady = $ada->submit(AssistantSettingsHandler::PATH, [], AssistantTestHandler::PATH);
        self::assertSame('/admin/assistant#assistant-test-title', $notReady->getHeaderLine('Location'));
        self::assertStringContainsString('The connection didn’t work. No AI service is chosen yet.', $this->page($ada, AssistantSettingsHandler::PATH));

        $this->connect();
        $works = $ada->submit(AssistantSettingsHandler::PATH, [], AssistantTestHandler::PATH, headers: self::XHR);
        self::assertSame('application/json', $works->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true, 'message' => 'The connection works: Test AI answered.'], json_decode((string) $works->getBody(), true));

        $this->connect('sk-wrong-0000');
        $refused = json_decode((string) $ada->submit(AssistantSettingsHandler::PATH, [], AssistantTestHandler::PATH, headers: self::XHR)->getBody(), true);
        self::assertSame(['ok' => false, 'message' => 'The connection didn’t work. The AI service didn’t accept the API key. An admin can replace it on the AI assistant screen.'], $refused);
    }

    public function testEditorsMayAskButNotSetItUp(): void
    {
        $this->turnOn();
        $this->connect();
        $bea = $this->browser('bea@example.org', 'editor');

        self::assertSame(403, $bea->request('GET', AssistantSettingsHandler::PATH)->getStatusCode());
        self::assertSame(403, $bea->submit('/admin', [], AssistantTestHandler::PATH)->getStatusCode());
        $dashboard = $this->page($bea, '/admin');
        self::assertStringContainsString('<aside class="admin-panel assistant-panel" aria-labelledby="assistant-panel-title"', $dashboard);
        self::assertStringContainsString('<h2 id="assistant-panel-title">Ask the assistant</h2>', $dashboard);
        self::assertStringContainsString('<p class="assistant-status" id="assistant-status" role="status"></p>', $dashboard);
        self::assertStringContainsString('assistant.js', $dashboard);
        self::assertStringNotContainsString('Share this page', $dashboard);

        $answer = $bea->submit('/admin', ['question' => 'How do I add a page to the main menu?', 'screen' => 'dashboard', 'return' => '/admin'], AssistantAskHandler::PATH);
        self::assertSame(200, $answer->getStatusCode());
        $html = (string) $answer->getBody();
        self::assertStringContainsString('<h1>The assistant’s answer</h1>', $html);
        self::assertStringContainsString('<strong>Your question:</strong> How do I add a page to the main menu?', $html);
        self::assertStringContainsString('<div class="assistant-suggestion" lang="en-GB">', $html);
        self::assertStringContainsString('Open Menus in the admin panel, choose the main menu, then add the page to it.', $html);
        self::assertStringContainsString('Try again', $html);
        self::assertStringContainsString('<a href="/admin">Discard and go back</a>', $html);
        self::assertStringNotContainsString('>Insert<', $html, 'an answer has no field to go into');

        $json = json_decode((string) $bea->submit('/admin', ['question' => 'How do I add a page to the main menu?'], AssistantAskHandler::PATH, headers: self::XHR)->getBody(), true);
        self::assertIsArray($json);
        $text = is_string($json['text'] ?? null) ? $json['text'] : '';
        unset($json['text']);
        self::assertSame(['ok' => true, 'heading' => 'The assistant’s answer', 'lang' => 'en-GB', 'notes' => [], 'status' => 'Suggestion ready', 'insert' => null], $json);
        self::assertMatchesRegularExpression('~^Open Menus in the admin panel, choose the main menu, then add the page to it\.\n\nhttps://docs\.sightpress\.org/latest/\S*$~', $text, 'a question about SightPress is answered from the documentation, with a link (A-5)');

        $empty = $bea->submit('/admin', ['question' => '  '], AssistantAskHandler::PATH, headers: self::XHR);
        self::assertSame([422, ['ok' => false, 'error' => 'Type your question for the assistant.']], [$empty->getStatusCode(), json_decode((string) $empty->getBody(), true)]);
        $busy = $bea->submit('/admin', ['question' => 'Help [[busy]]', 'return' => 'https://evil.example/'], AssistantAskHandler::PATH);
        self::assertSame(503, $busy->getStatusCode());
        $busyHtml = (string) $busy->getBody();
        self::assertStringContainsString('The assistant couldn’t help', $busyHtml);
        self::assertStringContainsString('The AI service is getting too many requests right now.', $busyHtml);
        self::assertStringContainsString('<a href="/admin">Go back</a>', $busyHtml, 'never another site');
        $uses = array_column(Services::get($this->site->container(), AssistantUsageStore::class)->byTask(gmdate('Y-m')), null, 'task');
        self::assertSame([3, 1], [$uses['help']['uses'] ?? null, $uses['help']['failed'] ?? null], 'two answers and the busy service; the empty question never reached it');

        $this->settings()->limit(500, ['admin', 'editor'], null);
        $long = json_decode((string) $bea->submit('/admin', ['question' => 'Tell me everything [[long]]'], AssistantAskHandler::PATH, headers: self::XHR)->getBody(), true);
        self::assertSame(['The answer was cut short because it was too long. Check its end.', 'This site has used 84% of this month’s AI assistant budget.'], is_array($long) ? $long['notes'] : null, 'two answers before (300 tokens) and this one (120) of 500');

        $this->settings()->limit(1, ['admin', 'editor'], null);
        $usedUp = $bea->submit('/admin', ['question' => 'Hello?'], AssistantAskHandler::PATH, headers: self::XHR);
        self::assertSame(429, $usedUp->getStatusCode());
        self::assertStringContainsString('has used all of its AI assistant budget', (string) $usedUp->getBody());
        $this->settings()->limit(null, ['admin'], null);
        self::assertStringNotContainsString('Ask the assistant', $this->page($bea, '/admin'), 'editors are no longer among the assistant’s roles');
        self::assertSame(403, $bea->submit('/admin', ['question' => 'Hello?'], AssistantAskHandler::PATH, headers: self::XHR)->getStatusCode());
    }

    public function testSuggestButtonsFillTheEditorsFieldsWithAndWithoutJavaScript(): void
    {
        $this->turnOn();
        $this->connect();
        $cleo = $this->browser('cleo@example.org', 'contributor');
        Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Volunteer'), authorId: $this->userId('cleo@example.org'));
        $own = $this->pageId + 1;

        $editor = $this->page($cleo, '/admin/pages/' . $own);
        self::assertStringContainsString('<button type="submit" form="assistant-suggest" name="field" value="summary" class="button button--secondary" data-assistant-target="en-gb-summary">Suggest a summary</button>', $editor);
        self::assertStringContainsString('data-assistant-target="en-gb-seo-title">Suggest a title for search results</button>', $editor);
        self::assertStringContainsString('data-assistant-target="en-gb-description">Suggest a description</button>', $editor);
        self::assertStringContainsString('<form action="/admin/assistant/ask" method="post" id="assistant-suggest" data-assistant-ask>', $editor);
        self::assertStringContainsString('<label for="assistant-share">Share this page with the assistant</label>', $editor);
        self::assertStringContainsString('<input type="hidden" name="target_prefix" value="en-gb">', $editor);

        $fields = ['field' => 'summary', 'page' => (string) $own, 'locale' => 'en-GB', 'target_prefix' => 'en-gb', 'screen' => 'page_editor', 'return' => '/admin/pages/' . $own];
        $json = json_decode((string) $cleo->submit('/admin/pages/' . $own, $fields, AssistantAskHandler::PATH, headers: self::XHR)->getBody(), true);
        self::assertSame(['ok' => true, 'heading' => 'Suggestion for “Summary”', 'text' => 'When the library is open, and the days it is closed.', 'lang' => 'en-GB', 'notes' => [], 'status' => 'Suggestion ready', 'insert' => ['field' => 'summary', 'text' => 'When the library is open, and the days it is closed.']], $json);

        $result = (string) $cleo->submit('/admin/pages/' . $own, $fields, AssistantAskHandler::PATH)->getBody();
        self::assertStringContainsString('<h1>Suggestion for “Summary”</h1>', $result);
        self::assertStringContainsString('<input type="hidden" name="target" value="en-gb-summary">', $result);
        $inserted = $cleo->submit('/admin/pages/' . $own, ['text' => 'When the library is open, and the days it is closed.'] + $fields + ['target' => 'en-gb-summary'], AssistantInsertHandler::PATH);
        self::assertSame('/admin/pages/' . $own . '#en-gb-summary', $inserted->getHeaderLine('Location'));
        $filled = $this->page($cleo, '/admin/pages/' . $own);
        self::assertStringContainsString('The suggestion is in “Summary”. Check it, then save.', $filled);
        self::assertMatchesRegularExpression('#<textarea id="en-gb-summary"[^>]*>When the library is open, and the days it is closed.</textarea>#', $filled);
        self::assertDoesNotMatchRegularExpression('#<textarea id="en-gb-summary"[^>]*>When the library#', $this->page($cleo, '/admin/pages/' . $own), 'only once, and never saved');
        self::assertSame(303, $cleo->submit('/admin/pages/' . $own, ['field' => 'nonsense', 'text' => 'x'], AssistantInsertHandler::PATH)->getStatusCode());

        $other = $cleo->submit('/admin/pages/' . $own, ['page' => (string) $this->pageId] + $fields, AssistantAskHandler::PATH, headers: self::XHR);
        self::assertSame(['ok' => false, 'error' => 'Save the page first: the assistant reads the saved page.'], json_decode((string) $other->getBody(), true), 'someone else’s page is not shared');
        $title = json_decode((string) $cleo->submit('/admin/pages/' . $own, ['field' => 'seo_title'] + $fields, AssistantAskHandler::PATH, headers: self::XHR)->getBody(), true);
        self::assertSame('Opening hours of Riverside Library', is_array($title) ? $title['text'] : null);
    }

    public function testSharingThePageSendsItsSavedText(): void
    {
        $this->turnOn();
        $this->connect();
        $ada = $this->browser('ada@example.org', 'admin');

        $ada->submit('/admin', ['question' => 'Is this clear?', 'share' => '1', 'page' => (string) $this->pageId, 'locale' => 'en-GB'], AssistantAskHandler::PATH, headers: self::XHR);
        $text = Services::get($this->site->container(), PageTexts::class);
        $admin = new Actor($this->userId('ada@example.org'), ['page.edit.any', 'page.edit.own', 'admin.access']);
        $saved = $text->forEditing($admin, $this->pageId, 'en-GB');
        self::assertSame([$this->pageId, 'en-GB', 'Opening hours', 'When we are open.'], [$saved?->pageId, $saved?->locale, $saved?->title, $saved?->summary]);
        self::assertNull($text->forEditing($admin, $this->pageId, 'pt-PT'), 'no translation yet');
        self::assertNull($text->forEditing($admin, 999, 'en-GB'));
    }

    public function testAskAndReturnAreChecked(): void
    {
        $ask = AssistantAsk::fromBody(['field' => 'seo_title', 'question' => str_repeat('a', 5000), 'share' => '1', 'page' => '12', 'locale' => 'pt-PT', 'screen' => 'page_editor', 'return' => '/admin/pages/12?locale=pt-PT', 'target_prefix' => 'pt-pt']);
        self::assertSame(['seo_title', AssistantAsk::MAX_QUESTION, true, 12, 'pt-PT', 'page_editor', '/admin/pages/12?locale=pt-PT', 'pt-pt-seo-title'], [$ask->field, mb_strlen($ask->question), $ask->share, $ask->pageId, $ask->locale, $ask->screen, $ask->return, $ask->target]);
        $odd = AssistantAsk::fromBody(['field' => 'title', 'page' => '0', 'locale' => 'x', 'screen' => 'people', 'target' => 'Bad id']);
        self::assertSame([null, false, null, null, null, '/admin', null], [$odd->field, $odd->share, $odd->pageId, $odd->locale, $odd->screen, $odd->return, $odd->target]);
        foreach (['//evil.example', 'https://evil.example/admin', '/admin//x', '/administer', '/admin/x y', '/admin\\x'] as $path) {
            self::assertSame('/admin', AssistantAsk::adminPath($path), $path);
        }
        self::assertSame('/admin/pages/3', AssistantAsk::adminPath('/admin/pages/3'));
    }

    private function turnOn(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'assistant', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    private function connect(string $key = self::KEY): void
    {
        $this->settings()->connect('test_ai', 'test-small', null, $key, null);
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');
    }

    private function settings(): AssistantSettings
    {
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');

        return Services::get($this->site->container(), AssistantSettings::class);
    }

    private function page(Browser $browser, string $path): string
    {
        return (string) $browser->request('GET', $path)->getBody();
    }

    private function userId(string $email): int
    {
        return (int) Services::get($this->site->container(), UserRepository::class)->findByEmail($email)?->id();
    }

    private function browser(string $email, string $role): Browser
    {
        $container = $this->site->container();
        if (Services::get($container, UserRepository::class)->findByEmail($email) === null) {
            $user = new User($email, ucfirst((string) strstr($email, '@', true)), 'Tester', 'Europe/London', new DateTimeImmutable());
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
            Services::get($container, UserRepository::class)->save($user);
            $roles = Services::get($container, RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? self::fail($role));
        }
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
