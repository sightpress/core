<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PageTranslationHandler;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PageTranslationHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PageTranslationHandlerTest extends TestCase
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
        $this->page = $this->fixture->pages->editor()->create('en-GB', new PageContent('Opening hours', summary: 'When.', description: 'Times.', blocks: [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Every day', 'level' => 2]],
        ]), null, (int) $this->contributor->id());
        $this->page->changeState(PageState::Published, new \DateTimeImmutable());
    }

    public function testStartsATranslationAsACopyAndOpensIt(): void
    {
        $response = $this->send($this->editor, ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'en-GB']);

        self::assertSame([303, '/admin/pages/' . $this->page->id() . '?locale=pt-PT'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Started the European Portuguese translation as a copy of the British English page. Translate the text, then publish the translation.'], array_map(strval(...), Flash::take($this->fixture->session)));
        $copy = $this->page->translation('pt-PT');
        self::assertSame(['Opening hours', true], [$copy?->title(), $copy?->isDraft()]);

        $this->send($this->editor, ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'en-GB']);
        self::assertSame(['The page is already written in "pt-PT". Change that translation instead.'], array_map(strval(...), Flash::take($this->fixture->session)));
    }

    public function testPublishesATranslationOnceItsChecksPassAndMakesItADraftAgain(): void
    {
        $this->fixture->pages->editor()->translate($this->page, 'pt-PT', 'en-GB');

        $this->send($this->editor, ['op' => 'publish', 'locale' => 'pt-PT']);
        self::assertSame(['Published the European Portuguese translation. Visitors can see it now.'], array_map(strval(...), Flash::take($this->fixture->session)));
        self::assertTrue($this->page->translation('pt-PT')?->isPublished());
        $this->send($this->editor, ['op' => 'publish', 'locale' => 'pt-PT']);
        self::assertSame(['Nothing was changed.'], array_map(strval(...), Flash::take($this->fixture->session)));

        $response = $this->send($this->editor, ['op' => 'draft', 'locale' => 'pt-PT']);
        self::assertSame('/admin/pages/' . $this->page->id() . '?locale=pt-PT', $response->getHeaderLine('Location'));
        self::assertSame(['The European Portuguese translation is a draft again. Visitors can’t see it.'], array_map(strval(...), Flash::take($this->fixture->session)));

        $this->page->changeState(PageState::Draft, new \DateTimeImmutable());
        $this->send($this->editor, ['op' => 'publish', 'locale' => 'pt-PT']);
        self::assertSame(['The European Portuguese translation is ready. Visitors can see it once the page is published.'], array_map(strval(...), Flash::take($this->fixture->session)));
    }

    public function testRefusedPublishingShowsTheProblems(): void
    {
        $media = new \SightPress\Core\Media\Entity\Media(str_repeat('a', 32), str_repeat('b', 64), new \SightPress\Core\Media\MediaFormat('image/png', \SightPress\Core\Media\Contract\MediaKind::Image, 'png', 'PNG'), 'f.png', 'photo.png', 1, 10, 10, [], null, new \DateTimeImmutable());
        $this->fixture->media()->save($media);
        $this->fixture->pages->editor()->save($this->page, 'pt-PT', new PageContent('Horário', blocks: [
            ['type' => 'image', 'version' => 1, 'data' => ['media' => (int) $media->id(), 'alt' => 'Uma foto']],
        ]));

        $response = $this->send($this->editor, ['op' => 'publish', 'locale' => 'pt-PT']);

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('/<section class="publish-report publish-report--blocked"/', (string) $response->getBody());
        self::assertTrue($this->page->translation('pt-PT')?->isDraft());
    }

    public function testRefusesWhatCantBeDone(): void
    {
        $member = $this->fixture->admin->auth->user('max@example.org', 'member');
        foreach ([
            [$member, ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'en-GB'], HttpForbiddenException::class],
            [$this->contributor, ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'en-GB'], HttpForbiddenException::class],
            [$this->contributor, ['op' => 'publish', 'locale' => 'pt-PT'], HttpForbiddenException::class],
            [$this->editor, ['op' => 'translate', 'locale' => 'fr-FR', 'from' => 'en-GB'], HttpBadRequestException::class],
            [$this->editor, ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'fr-FR'], HttpBadRequestException::class],
            [$this->editor, ['op' => 'teleport', 'locale' => 'pt-PT'], HttpBadRequestException::class],
            [$this->editor, ['op' => 'publish', 'locale' => 'pt-PT'], HttpBadRequestException::class],
        ] as $index => [$user, $body, $exception]) {
            try {
                $this->send($user, $body);
                self::fail((string) $index);
            } catch (\Throwable $thrown) {
                self::assertInstanceOf($exception, $thrown, (string) $index);
            }
        }
    }

    public function testPagesThePersonCantSeeAreNotFound(): void
    {
        $draft = $this->fixture->pages->editor()->create('en-GB', new PageContent('Secret'), null, (int) $this->editor->id());

        $this->expectException(HttpNotFoundException::class);
        $this->fixture->handle($this->fixture->translationHandler(), $this->contributor, 'POST', '/admin/pages/x/translations', ['op' => 'translate', 'locale' => 'pt-PT', 'from' => 'en-GB'], (int) $draft->id());
    }

    /**
     * @param array<string, string> $body
     */
    private function send(User $user, array $body): ResponseInterface
    {
        return $this->fixture->handle($this->fixture->translationHandler(), $user, 'POST', '/admin/pages/x/translations', $body, (int) $this->page->id());
    }
}
