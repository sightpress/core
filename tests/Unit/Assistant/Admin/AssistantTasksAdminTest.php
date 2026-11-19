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
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\Admin\Http\MediaItemHandler;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\Modules\TestAssistantModule;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The drafting tasks on their screens (A-4), through the kernel with the pretend "Test AI": Rewrite and Draft in the
 * panel, "Explain this" in the publish checks, and "Suggest alt text" on a picture, whose alt text stays marked —
 * and stops pages being published — until a person checks it.
 */
#[CoversClass(AssistantAskHandler::class)]
#[CoversClass(AssistantInsertHandler::class)]
#[CoversClass(AssistantAsk::class)]
#[CoversClass(AssistantAccess::class)]
#[CoversClass(Assistant::class)]
#[CoversClass(MediaItemHandler::class)]
#[CoversClass(PageEditorHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantTasksAdminTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private const array XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    private KernelSite $site;

    private int $pageId;

    protected function setUp(): void
    {
        $this->site = new KernelSite('assistant_tasks', locales: ['en-GB', 'pt-PT'], extraModules: [new ModuleCandidate(new TestAssistantModule(), 'acme/test-ai')]);
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'assistant', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        Services::get($this->site->container(), AssistantSettings::class)->connect('test_ai', 'test-vision', null, 'sk-test-1234', null);
        $this->pageId = (int) Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Book club', 'book-club'))->id();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testThePanelRewritesAndDraftsAndTheChecksExplainThemselves(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');
        $editor = $this->page($ada, '/admin/pages/' . $this->pageId);
        self::assertStringContainsString('<label for="assistant-rewrite-text">Text to rewrite</label>', $editor);
        self::assertStringContainsString('<label for="assistant-draft-brief">What should the page say?</label>', $editor);
        self::assertStringContainsString('<input type="hidden" name="task" value="explain_check">', $editor);
        self::assertMatchesRegularExpression('#Explain this<span class="visually-hidden"> check: [^<]+</span>#', $editor);
        $dashboard = $this->page($ada, '/admin');
        self::assertStringContainsString('Text to rewrite', $dashboard);
        self::assertStringNotContainsString('What should the page say?', $dashboard, 'drafts are for pages');

        $context = ['page' => (string) $this->pageId, 'locale' => 'en-GB', 'return' => '/admin/pages/' . $this->pageId];
        $rewrite = $this->json($ada, ['task' => 'rewrite', 'text' => 'We are open on every day of the week from 9 until 5.', 'style' => 'shorter'] + $context);
        self::assertSame(['Rewritten text', 'We are open every day from 9 to 5.', null], [$rewrite['heading'] ?? null, $rewrite['text'] ?? null, $rewrite['insert'] ?? null]);
        self::assertSame('Paste the text the assistant should rewrite.', $this->json($ada, ['task' => 'rewrite'] + $context)['error'] ?? null);

        $explain = $this->json($ada, ['task' => 'explain_check', 'text' => 'Add a description for search engines.'] + $context);
        self::assertSame('About this check', $explain['heading'] ?? null);
        self::assertStringStartsWith('Pictures need alt text', self::text($explain, 'text'));
        self::assertSame('The assistant couldn’t use what was sent.', $this->json($ada, ['task' => 'explain_check'] + $context)['error'] ?? null);

        $draft = $this->json($ada, ['task' => 'draft_page', 'question' => 'A page about our book club.'] + $context);
        self::assertSame('Draft of the page', $draft['heading'] ?? null);
        self::assertStringContainsString('## Join our book club', self::text($draft, 'text'));
        $insert = is_array($draft['insert'] ?? null) ? $draft['insert'] : [];
        self::assertSame('blocks', $insert['field'] ?? null);
        self::assertSame('Say what the page should be about.', $this->json($ada, ['task' => 'draft_page'] + $context)['error'] ?? null);

        $result = (string) $ada->submit('/admin/pages/' . $this->pageId, ['task' => 'draft_page', 'question' => 'A page about our book club.'] + $context, AssistantAskHandler::PATH)->getBody();
        self::assertStringContainsString('<h1>Draft of the page</h1>', $result);
        self::assertStringContainsString('<button type="submit">Add to the end of the page</button>', $result);
        $added = $ada->submit('/admin/pages/' . $this->pageId, ['field' => 'blocks', 'text' => self::text($insert, 'text')] + $context, AssistantInsertHandler::PATH);
        self::assertSame('/admin/pages/' . $this->pageId, $added->getHeaderLine('Location'));
        $filled = $this->page($ada, '/admin/pages/' . $this->pageId);
        self::assertStringContainsString('The draft is at the end of the page’s blocks. Check it, then save.', $filled);
        self::assertStringContainsString('value="Join our book club"', $filled);
        self::assertStringContainsString('We meet on the first Tuesday of each month.', $filled);
        self::assertSame([], Services::get($this->site->container(), PageRepository::class)->find($this->pageId)?->translation('en-GB')?->blocks(), 'not saved');

        $ada->submit('/admin/pages/' . $this->pageId, ['field' => 'blocks', 'text' => '{"not": "a list"}'] + $context, AssistantInsertHandler::PATH);
        self::assertStringNotContainsString('The draft is at the end', $this->page($ada, '/admin/pages/' . $this->pageId));
        $ada->submit('/admin/pages/' . $this->pageId, ['field' => 'blocks', 'text' => '[{"type": "image", "data": {"media": 1}}, "x", {"type": "heading", "data": {"text": "Kept", "level": 2}}]'] + $context, AssistantInsertHandler::PATH);
        $odd = $this->page($ada, '/admin/pages/' . $this->pageId);
        self::assertStringContainsString('value="Kept"', $odd);
        self::assertStringNotContainsString('Block 2 of 2: Image', $odd, 'only headings and text');
    }

    public function testAltTextFromTheAssistantNeedsAPersonToCheckIt(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');
        $directory = new TemporaryDirectory();
        $file = $directory->write('room.jpg', MediaFixture::jpeg(1600, 1200));
        $media = Services::get($this->site->container(), MediaUploader::class)->uploadFile(new \Nyholm\Psr7\UploadedFile($file, (int) filesize($file), UPLOAD_ERR_OK, 'room.jpg', 'image/jpeg'), 1)->media;
        $directory->remove();
        $path = '/admin/media/' . $media->id();

        $screen = $this->page($ada, $path);
        self::assertStringContainsString('value="alt_0" class="button button--secondary" data-assistant-target="details-alt-0" data-assistant-suggestion="alt-suggestion-0">Suggest alt text in British English</button>', $screen);
        self::assertStringContainsString('Suggest alt text in European Portuguese', $screen);
        self::assertStringContainsString('<input type="hidden" name="media" value="' . $media->id() . '">', $screen);

        $json = $this->json($ada, ['field' => 'alt_1', 'media' => (string) $media->id(), 'screen' => 'media_item', 'return' => $path]);
        self::assertSame(['Suggestion for “Alt text”', 'A reading room with tall windows and children reading on cushions.', 'pt-PT', ['field' => 'alt_1', 'text' => 'A reading room with tall windows and children reading on cushions.']], [$json['heading'] ?? null, $json['text'] ?? null, $json['lang'] ?? null, $json['insert'] ?? null]);
        self::assertSame('The assistant couldn’t use what was sent.', $this->json($ada, ['field' => 'alt_5', 'media' => (string) $media->id()])['error'] ?? null);
        self::assertSame('The assistant couldn’t use what was sent.', $this->json($ada, ['field' => 'alt_0', 'media' => '999'])['error'] ?? null, 'no such picture');
        self::assertSame('The assistant couldn’t use what was sent.', $this->json($ada, ['field' => 'alt_0'])['error'] ?? null);

        $inserted = $ada->submit($path, ['field' => 'alt_0', 'text' => 'A reading room.', 'return' => $path], AssistantInsertHandler::PATH);
        self::assertSame($path . '#details-alt-0', $inserted->getHeaderLine('Location'));
        $filled = $this->page($ada, $path);
        self::assertStringContainsString('The suggestion is in “Alt text”. Check it, then save.', $filled);
        self::assertMatchesRegularExpression('#<textarea id="details-alt-0"[^>]*>A reading room.</textarea>#', $filled);
        self::assertStringContainsString('<input type="hidden" name="alt_suggestion_0" id="alt-suggestion-0" value="A reading room.">', $filled);
        self::assertStringContainsString('I checked the alt text in British English: it describes the picture', $filled);

        $ada->submit($path, ['alt_0' => 'A reading room.', 'alt_suggestion_0' => 'A reading room.', 'published' => '1'], $path);
        self::assertTrue($this->media((int) $media->id())->isAltSuggested('en-GB'));
        $page = Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Rooms', 'rooms', 'Our rooms.', 'The rooms of the library.', [['type' => 'image', 'version' => 1, 'data' => ['media' => (int) $media->id()]]]));
        self::assertSame(['media.alt_unchecked'], self::unchecked(Services::get($this->site->container(), PublishChecker::class)->check($page)->errors()));
        self::assertStringContainsString('The AI assistant suggested this alt text.', $this->page($ada, $path));

        $failed = $ada->submit($path, ['alt_0' => str_repeat('x', 1001), 'alt_suggestion_0' => 'A reading room.'], $path);
        self::assertSame(422, $failed->getStatusCode());
        self::assertStringContainsString('value="A reading room."', (string) $failed->getBody(), 'the suggestion stays with the form');

        $ada->submit($path, ['alt_0' => 'A reading room.', 'alt_suggestion_0' => 'A reading room.', 'alt_checked_0' => '1', 'published' => '1'], $path);
        self::assertFalse($this->media((int) $media->id())->isAltSuggested('en-GB'));
        self::assertSame([], self::unchecked(Services::get($this->site->container(), PublishChecker::class)->check($page)->errors()));
    }

    public function testWithoutAModelThatSeesPicturesThereIsNoAltTextButton(): void
    {
        Services::get($this->site->container(), AssistantSettings::class)->connect('test_ai', 'test-small', null, null, null);
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');
        $ada = $this->browser('ada@example.org', 'admin');
        $directory = new TemporaryDirectory();
        $file = $directory->write('a.png', MediaFixture::png());
        $media = Services::get($this->site->container(), MediaUploader::class)->uploadFile(new \Nyholm\Psr7\UploadedFile($file, (int) filesize($file), UPLOAD_ERR_OK, 'a.png', 'image/png'), 1)->media;
        $directory->remove();

        $screen = $this->page($ada, '/admin/media/' . $media->id());
        self::assertStringNotContainsString('Suggest alt text', $screen);
        self::assertStringContainsString('Ask the assistant', $screen, 'questions and rewrites still are');
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<mixed>
     */
    private function json(Browser $browser, array $fields): array
    {
        $answer = json_decode((string) $browser->submit('/admin/pages/' . $this->pageId, $fields, AssistantAskHandler::PATH, headers: self::XHR)->getBody(), true);

        return is_array($answer) ? $answer : [];
    }

    /**
     * @param list<\SightPress\Core\Page\Contract\PublishIssue> $issues
     *
     * @return list<string>
     */
    private static function unchecked(array $issues): array
    {
        $rules = [];
        foreach ($issues as $issue) {
            if ($issue->rule === 'media.alt_unchecked') {
                $rules[] = $issue->rule;
            }
        }

        return $rules;
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $name): string
    {
        return is_string($data[$name] ?? null) ? $data[$name] : '';
    }

    private function media(int $id): \SightPress\Core\Media\Entity\Media
    {
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');

        return Services::get($this->site->container(), MediaRepository::class)->find($id) ?? self::fail('No file.');
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
