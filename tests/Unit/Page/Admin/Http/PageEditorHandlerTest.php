<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PageEditorHandler::class)]
#[CoversClass(PageEditorScreen::class)]
#[CoversClass(PageAdmin::class)]
#[UsesNamespace('SightPress\Core')]
final class PageEditorHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private User $contributor;

    private Page $page;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->contributor = $this->fixture->admin->auth->user('cleo@example.org', 'contributor');
        $this->fixture->pages->addresses()->setHome($this->fixture->pages->published('Home'));
        $this->page = $this->fixture->pages->editor()->create('en-GB', new PageContent(
            'Opening hours',
            summary: 'When we are open.',
            blocks: [
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Every day', 'level' => 2]],
                ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>From <strong>9</strong>.</p>']],
            ],
        ), null, (int) $this->contributor->id());
    }

    public function testOnlyPeopleWhoWritePagesMayOpenIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->get($this->fixture->admin->auth->user('max@example.org', 'member'));
    }

    public function testAPageThatIsntThereOrCantBeSeenIsNotFound(): void
    {
        $other = $this->fixture->pages->editor()->create('en-GB', new PageContent('Not yours'), null, (int) $this->editor->id());
        foreach ([[null, $this->editor], [999, $this->editor], [(int) $other->id(), $this->contributor]] as [$id, $user]) {
            try {
                $request = $this->fixture->admin->request($user, 'GET', '/admin/pages/x', [], $this->fixture->session);
                $this->fixture->editorHandler()->handle($id === null ? $request : $request->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $id));
                self::fail('Expected a 404.');
            } catch (HttpNotFoundException $exception) {
                self::assertStringContainsString('no page', $exception->getMessage());
            }
        }

        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('This site isn’t written in that language.');
        $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'GET', '/admin/pages/1?locale=fr-FR', id: (int) $this->page->id());
    }

    public function testShowsTheSavedPageWithItsChecksAndWorkflowButtons(): void
    {
        $image = $this->image();
        $this->fixture->pages->editor()->save($this->page, 'en-GB', new PageContent('Opening hours', 'opening', 'When we are open.', 'Times.', $this->page->translation('en-GB')?->blocks() ?? [], $image, 'Hours', true, 'https://example.org/hours'));

        $html = $this->get($this->contributor);

        self::assertStringContainsString('<title>Edit “Opening hours” - Admin - Demo</title>', $html);
        self::assertStringContainsString('State: Draft · Address: /opening · <a href="/opening">View the saved page</a>', $html);
        self::assertStringContainsString('<form id="en-gb" action="/admin/pages/' . $this->page->id() . '" method="post" novalidate>', $html);
        self::assertStringContainsString('id="en-gb-title" value="Opening hours"', $html);
        self::assertStringContainsString('<option value="media-' . $image . '" selected>photo.png (PNG), not published yet</option>', $html);
        self::assertStringContainsString('6 characters. Search engines show about 160.', $html);
        self::assertStringContainsString('<p class="search-preview__title">Hours - Demo</p>', $html);
        self::assertStringContainsString('<p class="search-preview__url">https://demo.example/opening</p>', $html);
        self::assertStringContainsString('<p class="search-preview__description">Times.</p>', $html);
        self::assertStringContainsString('<legend>Block 2 of 2: Text</legend>', $html);
        self::assertStringContainsString('<div class="block-editor__preview-content"><div class="block-rich-text"><p>From <strong>9</strong>.</p></div></div>', $html);
        self::assertStringContainsString('aria-label="Move up: block 2, Text"', $html);
        self::assertStringContainsString('id="publish-report"', $html);
        self::assertStringContainsString('<button type="submit" name="transition" value="submit">Submit for review</button>', $html);
        self::assertStringContainsString('<form action="/admin/pages/' . $this->page->id() . '/workflow" method="post">', $html);
    }

    public function testFallsBackWhenThereIsLittleToShow(): void
    {
        $page = $this->fixture->pages->editor()->create('en-GB', new PageContent('Bare'), null, (int) $this->editor->id());
        $page->setLayout('gone', new DateTimeImmutable());

        $html = (string) $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'GET', '/admin/pages/x', id: (int) $page->id())->getBody();
        self::assertStringContainsString('No description or summary yet', $html);
        self::assertStringContainsString('Gone (not in this theme: the standard page is used)', $html);
        self::assertStringNotContainsString('social_image', $html, 'no pictures, no share image field');
        self::assertStringContainsString('There are no blocks yet. Add the first one below.', $html);

        $untranslated = (string) $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'GET', '/admin/pages/x?locale=pt-PT', id: (int) $page->id())->getBody();
        self::assertStringContainsString('<title>Edit “Page ' . $page->id() . '” - Admin - Demo</title>', $untranslated);
        self::assertStringContainsString('action="/admin/pages/' . $page->id() . '?locale=pt-PT"', $untranslated);
        self::assertStringContainsString('<p class="search-preview__title">Untitled page</p>', $untranslated);
        self::assertStringNotContainsString('id="publish-report"', $untranslated);
    }

    public function testPeopleWhoCantChangeThePageNowStillSeeItAndItsButtons(): void
    {
        $this->fixture->workflow()->apply($this->page, PageTransition::Submit, $this->actor($this->contributor));

        $html = $this->get($this->contributor);

        self::assertStringContainsString('You can’t change this page now: it is waiting for review.', $html);
        self::assertStringNotContainsString('<form id="en-gb"', $html);
        self::assertStringNotContainsString('Review and publishing', $html, 'no buttons for them now');

        $this->expectException(HttpForbiddenException::class);
        $this->post($this->contributor, ['op' => 'save']);
    }

    public function testButtonsChangeTheBlocksWithoutSaving(): void
    {
        $response = $this->post($this->editor, $this->body(['op' => 'add:/blocks', 'add_type' => ['/blocks' => 'image']]));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Added a Image block. It is block 3 of 3. Save the page to keep this change.', $html);
        self::assertStringContainsString('<fieldset class="block-editor" id="en-gb-blocks-2" tabindex="-1" autofocus>', $html);
        self::assertStringContainsString('value="A changed title"', $html, 'the form keeps what was typed');
        self::assertCount(1, $this->fixture->pages->repository()->revisions($this->page), 'nothing saved');

        $moved = (string) $this->post($this->editor, $this->body(['op' => 'up:/blocks/1']))->getBody();
        self::assertStringContainsString('Moved block 2 (Text) up. It is now block 1 of 2.', $moved);
        self::assertStringContainsString('id="en-gb-blocks-0-down" class="button button--secondary" aria-label="Move down: block 1, Text" autofocus', $moved);

        $preview = (string) $this->post($this->editor, $this->body(['op' => 'preview']))->getBody();
        self::assertStringContainsString('The previews show your changes. Save the page to keep them.', $preview);
        self::assertStringContainsString('id="search-preview" aria-labelledby="search-preview-title" tabindex="-1" autofocus', $preview);
        self::assertStringContainsString('<p class="search-preview__title">A changed title - Demo</p>', $preview);

        $stale = (string) $this->post($this->editor, $this->body(['op' => 'up:/blocks/7']))->getBody();
        self::assertStringContainsString('That button no longer matches the page. Nothing was changed; try again.', $stale);
        self::assertStringContainsString('That button no longer matches', (string) $this->post($this->editor, $this->body(['op' => 'add:/blocks', 'add_type' => 'not a list']))->getBody());
    }

    public function testPreviewsRichTextInsideColumnsOnlyWhenItCanBeSaved(): void
    {
        $html = (string) $this->post($this->editor, $this->body(['op' => 'preview', 'blocks' => [
            ['type' => 'columns', 'version' => '1', 'data' => ['columns' => [
                ['item' => '1', 'blocks' => [['type' => 'rich_text', 'version' => '1', 'data' => ['html' => '<p>Left <em>side</em></p>']]]],
                ['item' => '1', 'blocks' => [['type' => 'rich_text', 'version' => '1', 'data' => ['html' => '']]]],
            ]]],
            ['type' => 'gone.block', 'version' => '1', 'kept' => '{}'],
        ]]))->getBody();

        self::assertStringContainsString('<summary>Preview of block 1, Text</summary>', $html);
        self::assertSame(1, substr_count($html, '<summary>Preview of'), 'the empty text has no preview');
        self::assertStringContainsString('<div class="block-editor__preview-content"><div class="block-rich-text"><p>Left <em>side</em></p></div></div>', $html);
        self::assertStringContainsString('This kind of block isn’t installed on the site', $html);
    }

    public function testSavesARevisionAndSaysSo(): void
    {
        $image = $this->image();
        $response = $this->post($this->editor, $this->body(['layout' => 'home', 'social_image' => 'media-' . $image, 'noindex' => '1']));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/pages/' . $this->page->id(), $response->getHeaderLine('Location'));
        self::assertSame(['Saved “A changed title”.'], Flash::take($this->fixture->session));
        $translation = $this->page->translation('en-GB');
        self::assertSame(['A changed title', 'home', $image, true], [$translation?->title(), $this->page->layout(), $translation?->socialImage(), $translation?->isNoindex()]);
        self::assertCount(2, $this->fixture->pages->repository()->revisions($this->page));
        self::assertSame('Every day', $translation?->blocks()[0]['data']['text'] ?? null);

        $this->page->changeState(\SightPress\Core\Rbac\Contract\PageState::Published, new DateTimeImmutable());
        $this->post($this->editor, $this->body(['title' => 'Live now']));
        self::assertSame(['Saved “Live now”. The changes are on the site now.'], Flash::take($this->fixture->session));

        $this->fixture->pages->site->locales === ['en-GB', 'pt-PT'] || self::fail('The fixture site is written in two languages.');
        $translated = $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'POST', '/admin/pages/x?locale=pt-PT', $this->body(['title' => 'Horário']), (int) $this->page->id());
        self::assertSame('/admin/pages/' . $this->page->id() . '?locale=pt-PT', $translated->getHeaderLine('Location'));
        self::assertSame('Horário', $this->page->translation('pt-PT')?->title());
    }

    public function testExplainsWhyThePageCantBeSaved(): void
    {
        $tooLong = $this->post($this->editor, $this->body(['title' => str_repeat('x', 201)]));
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertStringContainsString('The title must be 200 characters or fewer', (string) $tooLong->getBody());

        $blocks = $this->post($this->editor, $this->body(['blocks' => [['type' => 'heading', 'version' => '1', 'data' => ['text' => '', 'level' => '2']]]]));
        self::assertSame(422, $blocks->getStatusCode());
        $html = (string) $blocks->getBody();
        self::assertStringContainsString('<title>1 error: Edit “Opening hours”', $html);
        self::assertStringContainsString('<li><a href="#en-gb-blocks-0-data-text">Heading text: Fill in this field.</a></li>', $html);
        self::assertStringContainsString('The page has not been saved.', $html);

        $slug = $this->post($this->editor, $this->body(['slug' => 'Not A Slug']));
        self::assertSame(422, $slug->getStatusCode());
        self::assertStringContainsString('<a href="#en-gb-slug">The address &quot;Not A Slug&quot; can only use', (string) $slug->getBody());
        self::assertCount(1, $this->fixture->pages->repository()->revisions($this->page));
    }

    public function testListsTheTranslationsWithTheirStatusAndOffersToStartOne(): void
    {
        $html = $this->get($this->editor);

        self::assertStringContainsString('<h2 id="translations-title">Translations</h2>', $html);
        self::assertStringContainsString('<th scope="row" lang="en-GB">British English</th>', $html);
        self::assertStringContainsString('You are editing it now.', $html);
        self::assertStringContainsString('Not written yet', $html);
        self::assertStringContainsString('Translate into European Portuguese from British English', $html);
        self::assertStringContainsString('Write the European Portuguese translation from scratch', $html);

        $this->fixture->pages->editor()->translate($this->page, 'pt-PT', 'en-GB');
        $draft = (string) $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'GET', '/admin/pages/x?locale=pt-PT', id: (int) $this->page->id())->getBody();
        self::assertStringContainsString('Draft translation', $draft);
        self::assertStringContainsString('Edit the British English translation', $draft);
        self::assertStringContainsString('<button type="submit" name="op" value="publish">Publish this translation</button>', $draft);

        $this->fixture->pages->editor()->publishTranslation($this->page, 'pt-PT', true);
        $ready = (string) $this->fixture->handle($this->fixture->editorHandler(), $this->editor, 'GET', '/admin/pages/x?locale=pt-PT', id: (int) $this->page->id())->getBody();
        self::assertStringContainsString('Make this translation a draft again', $ready);
        self::assertStringNotContainsString('Publish this translation', $this->get($this->contributor));
    }

    public function testASiteInOneLanguageHasNoTranslationsSection(): void
    {
        $fixture = new PageAdminFixture(['en-GB']);
        $editor = $fixture->admin->auth->user('bea@example.org', 'editor');
        $page = $fixture->pages->editor()->create('en-GB', new PageContent('About'));

        $html = (string) $fixture->handle($fixture->editorHandler(), $editor, 'GET', '/admin/pages/x', id: (int) $page->id())->getBody();

        self::assertStringContainsString('<h1>Edit “About”</h1>', $html);
        self::assertStringNotContainsString('translations-title', $html);
    }

    private function image(): int
    {
        $media = new Media(str_repeat('a', 32), str_repeat('b', 64), new MediaFormat('image/png', MediaKind::Image, 'png', 'PNG'), 'f.png', 'photo.png', 1, 10, 10, [], null, new DateTimeImmutable());
        $this->fixture->media()->save($media);

        return (int) $media->id();
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function body(array $changes = []): array
    {
        return $changes + [
            'op' => 'save',
            'title' => 'A changed title',
            'slug' => '',
            'layout' => 'default',
            'summary' => 'When we are open.',
            'seo_title' => '',
            'description' => '',
            'canonical_url' => '',
            'blocks' => [
                ['type' => 'heading', 'version' => '1', 'data' => ['text' => 'Every day', 'level' => '2']],
                ['type' => 'rich_text', 'version' => '1', 'data' => ['html' => '<p>From 9.</p>']],
            ],
        ];
    }

    private function actor(User $user): Actor
    {
        $id = (int) $user->id();

        return new Actor($id, $this->fixture->admin->auth->database->roles()->permissionNamesOf($id));
    }

    private function get(User $user): string
    {
        return (string) $this->fixture->handle($this->fixture->editorHandler(), $user, 'GET', '/admin/pages/x', id: (int) $this->page->id())->getBody();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): ResponseInterface
    {
        return $this->fixture->handle($this->fixture->editorHandler(), $user, 'POST', '/admin/pages/x', $body, (int) $this->page->id());
    }
}
