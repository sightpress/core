<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Admin\AssistantTranslateHandler;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\Http\PageRevisionsHandler;
use SightPress\Core\Page\Admin\Http\PageTranslationHandler;
use SightPress\Core\Page\Contract\TranslationDrafts;
use SightPress\Core\Page\Contract\TranslationSource;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\TranslationDraftService;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\Modules\TestAssistantModule;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * "Draft a translation into … with the AI assistant" (A-6), through the kernel with the pretend "Test AI": the draft
 * keeps the page's blocks, links and media, is marked as the assistant's in the editor, the page list and the
 * versions, and can't be published until a person edits it or says they checked it.
 */
#[CoversClass(AssistantTranslateHandler::class)]
#[CoversClass(TranslationDraftService::class)]
#[CoversClass(PageEditor::class)]
#[CoversClass(PageTranslation::class)]
#[CoversClass(PublishChecker::class)]
#[CoversClass(PageTranslationHandler::class)]
#[CoversClass(PageEditorScreen::class)]
#[CoversClass(PageListHandler::class)]
#[CoversClass(PageRevisionsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantTranslateTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private int $pageId;

    protected function setUp(): void
    {
        $this->site = new KernelSite('assistant_translate', locales: ['en-GB', 'pt-PT', 'es'], extraModules: [new ModuleCandidate(new TestAssistantModule(), 'acme/test-ai')]);
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'assistant', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        Services::get($this->site->container(), AssistantSettings::class)->connect('test_ai', 'test-small', null, 'sk-test-1234', null);
        $this->pageId = (int) Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Book club', 'book-club', 'We read together.', 'A book club for everyone.', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Join us', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Every <a href="/events">month</a>.</p>']],
        ]))->id();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheAssistantDraftsATranslationThatAPersonMustCheck(): void
    {
        $bea = $this->browser('bea@example.org', 'editor');
        $editor = $this->page($bea, '/admin/pages/' . $this->pageId);
        self::assertStringContainsString('<button type="submit" class="button button--secondary">Draft a translation into European Portuguese with the AI assistant</button>', $editor);
        self::assertStringContainsString('<input type="hidden" name="from" value="en-GB">', $editor);

        $drafted = $bea->submit('/admin/pages/' . $this->pageId, ['page' => (string) $this->pageId, 'from' => 'en-GB', 'to' => 'pt-PT'], AssistantTranslateHandler::PATH);
        self::assertSame('/admin/pages/' . $this->pageId . '?locale=pt-PT#machine-drafted', $drafted->getHeaderLine('Location'));
        $translation = $this->translation('pt-PT');
        self::assertSame(['Tradução: Book club', 'traducao-book-club', 'Tradução: We read together.', 'Tradução: A book club for everyone.', true, true], [$translation->title(), $translation->slug(), $translation->summary(), $translation->description(), $translation->isDraft(), $translation->isMachineDrafted()]);
        self::assertSame([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Tradução: Join us', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Every <a href="/events">month</a>.</p>']],
        ], $translation->blocks(), 'same blocks and links');

        $screen = $this->page($bea, '/admin/pages/' . $this->pageId . '?locale=pt-PT');
        self::assertStringContainsString('The AI assistant drafted the European Portuguese translation.', $screen);
        self::assertStringContainsString('The AI assistant drafted this translation, and nobody has checked it yet.', $screen);
        self::assertStringContainsString('<button type="submit" name="op" value="confirm" class="button button--secondary">I checked this translation</button>', $screen);
        self::assertStringContainsString('Drafted by the AI assistant, not checked yet', $screen, 'in the Translations table');
        self::assertStringContainsString('Translation drafted by the AI assistant, not checked yet: European Portuguese', $this->page($bea, '/admin/pages'));
        self::assertStringContainsString('Drafted by the AI assistant, not checked yet', $this->page($bea, '/admin/pages/' . $this->pageId . '/revisions?locale=pt-PT'));

        $page = Services::get($this->site->container(), PageRepository::class)->find($this->pageId) ?? self::fail('No page.');
        self::assertSame(['translation.machine_drafted'], array_values(array_unique(array_map(static fn ($issue): string => $issue->rule, array_filter(Services::get($this->site->container(), PublishChecker::class)->check($page, 'pt-PT')->errors(), static fn ($issue): bool => $issue->rule === 'translation.machine_drafted')))));
        $refused = $bea->submit('/admin/pages/' . $this->pageId . '?locale=pt-PT', ['locale' => 'pt-PT', 'op' => 'publish'], PageTranslationHandler::path($this->pageId));
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('The AI assistant drafted this translation, and nobody has checked it yet. Read it and change what needs changing', (string) $refused->getBody());

        $bea->submit('/admin/pages/' . $this->pageId . '?locale=pt-PT', ['locale' => 'pt-PT', 'op' => 'confirm'], PageTranslationHandler::path($this->pageId));
        self::assertFalse($this->translation('pt-PT')->isMachineDrafted());
        self::assertStringContainsString('The European Portuguese translation is checked. It can be published now.', $this->page($bea, '/admin/pages/' . $this->pageId . '?locale=pt-PT'));
        $bea->submit('/admin/pages/' . $this->pageId . '?locale=pt-PT', ['locale' => 'pt-PT', 'op' => 'confirm'], PageTranslationHandler::path($this->pageId));
        self::assertStringContainsString('Nothing was changed.', $this->page($bea, '/admin/pages/' . $this->pageId . '?locale=pt-PT'));
        self::assertSame(400, $bea->submit('/admin/pages/' . $this->pageId, ['locale' => 'es', 'op' => 'confirm'], PageTranslationHandler::path($this->pageId))->getStatusCode());
        $archived = Services::get($this->site->container(), PageRepository::class)->find($this->pageId) ?? self::fail('No page.');
        $archived->changeState(\SightPress\Core\Rbac\Contract\PageState::Archived, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();
        self::assertSame(403, $bea->submit('/admin/pages', ['locale' => 'pt-PT', 'op' => 'confirm'], PageTranslationHandler::path($this->pageId))->getStatusCode(), 'an archived page can be seen but not changed');
    }

    public function testAPersonsEditClearsTheMarkAndOnlyEditorsOfThePageMayDraft(): void
    {
        $drafts = Services::get($this->site->container(), TranslationDrafts::class);
        $admin = new Actor(1, ['page.edit.any', 'page.edit.own', 'admin.access']);
        $source = $drafts->source($admin, $this->pageId, 'en-GB', 'es') ?? self::fail('No source.');
        self::assertSame('Book club', $source->title);
        self::assertNull($drafts->source(new Actor(5, ['admin.access']), $this->pageId, 'en-GB', 'es'), 'may not edit it');
        self::assertNull($drafts->source($admin, $this->pageId, 'en-GB', 'fr'), 'no such language on the site');
        self::assertNull($drafts->source($admin, $this->pageId, 'pt-PT', 'es'), 'not written in that language');
        self::assertNull($drafts->source($admin, 999, 'en-GB', 'es'));

        self::assertNull($drafts->save($admin, $this->pageId, 'en-GB', 'es', new TranslationSource('¡!', null, null, null, $source->blocks)));
        self::assertSame('page', $this->translation('es')->slug(), 'no letters for an address: “page”');
        self::assertSame('The translation can’t be started: the page changed, or you can’t change it now.', $drafts->save($admin, $this->pageId, 'en-GB', 'es', $source)?->text, 'already written');
        self::assertSame('The translation’s blocks didn’t pass the checks, so it wasn’t saved. Try again.', $drafts->save($admin, $this->pageId, 'en-GB', 'pt-PT', new TranslationSource('Clube', null, null, null, [['type' => 'nonsense', 'version' => 1, 'data' => []]]))?->text);
        Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Other', 'other'))->translations();
        $taken = Services::get($this->site->container(), PageRepository::class)->find($this->pageId + 1) ?? self::fail('No page.');
        Services::get($this->site->container(), PageEditor::class)->save($taken, 'pt-PT', new PageContent('Clube', 'clube'));
        self::assertNotNull($drafts->save($admin, $this->pageId, 'en-GB', 'pt-PT', new TranslationSource('Clube', null, null, null, [])), 'the address is taken');

        $page = Services::get($this->site->container(), PageRepository::class)->find($this->pageId) ?? self::fail('No page.');
        $editor = Services::get($this->site->container(), PageEditor::class);
        $editor->save($page, 'es', $this->translation('es')->content());
        self::assertFalse($this->translation('es')->isMachineDrafted(), 'a person saved it');
        try {
            $editor->draftTranslation($page, 'es', new PageContent('Otra vez'));
            self::fail('already written');
        } catch (\SightPress\Core\Page\PageException $exception) {
            self::assertStringContainsString('already written', $exception->getMessage());
        }
        try {
            $editor->confirmTranslation($page, 'pt-PT');
            self::fail('not written');
        } catch (\SightPress\Core\Page\PageException $exception) {
            self::assertStringContainsString('isn\'t written', $exception->getMessage());
        }
    }

    public function testWhatGoesWrongIsSaidOnTheEditor(): void
    {
        $bea = $this->browser('bea@example.org', 'editor');
        $back = '/admin/pages/' . $this->pageId;

        self::assertSame('/admin/pages', $bea->submit($back, ['page' => 'x', 'from' => 'en-GB', 'to' => 'pt-PT'], AssistantTranslateHandler::PATH)->getHeaderLine('Location'));
        self::assertSame($back, $bea->submit($back, ['page' => (string) $this->pageId, 'from' => 'en-GB'], AssistantTranslateHandler::PATH)->getHeaderLine('Location'));
        self::assertStringContainsString('The translation can’t be started', $this->page($bea, $back));

        $page = Services::get($this->site->container(), PageRepository::class)->find($this->pageId) ?? self::fail('No page.');
        Services::get($this->site->container(), PageEditor::class)->save($page, 'en-GB', new PageContent('Book club', 'book-club', null, null, array_fill(0, 2, ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>' . str_repeat('Long text. ', 3000) . '</p>']])));
        $bea->submit($back, ['page' => (string) $this->pageId, 'from' => 'en-GB', 'to' => 'pt-PT'], AssistantTranslateHandler::PATH);
        self::assertStringContainsString('This page is too long for the assistant to translate in one go.', $this->page($bea, $back));

        Services::get($this->site->container(), PageEditor::class)->save($page, 'en-GB', new PageContent('Book club [[busy]]', 'book-club'));
        $bea->submit($back, ['page' => (string) $this->pageId, 'from' => 'en-GB', 'to' => 'pt-PT'], AssistantTranslateHandler::PATH);
        self::assertStringContainsString('The assistant couldn’t draft the translation. The AI service is getting too many requests right now.', $this->page($bea, $back));

        Services::get($this->site->container(), PageEditor::class)->save($page, 'en-GB', new PageContent('Book club', 'book-club'));
        Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Clube', 'other'));
        $other = Services::get($this->site->container(), PageRepository::class)->find($this->pageId + 1) ?? self::fail('No page.');
        Services::get($this->site->container(), PageEditor::class)->save($other, 'pt-PT', new PageContent('Tradução: Book club', 'traducao-book-club'));
        $bea->submit($back . '?locale=en-GB', ['page' => (string) $this->pageId, 'from' => 'en-GB', 'to' => 'pt-PT'], AssistantTranslateHandler::PATH);
        self::assertStringContainsString('address', $this->page($bea, $back), 'the address of the translation is taken');
    }

    private function translation(string $locale): PageTranslation
    {
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');

        return Services::get($this->site->container(), PageRepository::class)->find($this->pageId)?->translation($locale) ?? self::fail('No ' . $locale . ' translation.');
    }

    private function page(Browser $browser, string $path): string
    {
        return (string) $browser->request('GET', $path)->getBody();
    }

    private function browser(string $email, string $role): Browser
    {
        $container = $this->site->container();
        $user = new User($email, ucfirst((string) strstr($email, '@', true)), 'Tester', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? self::fail($role));
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
