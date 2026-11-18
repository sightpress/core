<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Admin\Http\LanguagesHandler;
use SightPress\Core\Admin\Http\RemoveLanguageHandler;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Site\Contract\LocaleRemoved;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(LanguagesHandler::class)]
#[CoversClass(RemoveLanguageHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class LanguagesHandlerTest extends TestCase
{
    private AdminFixture $fixture;

    private RecordingEvents $events;

    private Session $session;

    private User $admin;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->events = new RecordingEvents();
        $this->session = new Session();
        $this->admin = $this->fixture->auth->user('ada@example.org', 'admin');
    }

    public function testOnlyPeopleWhoManageSettingsMayUseThem(): void
    {
        $editor = $this->fixture->auth->user('bea@example.org', 'editor');
        foreach ([fn () => $this->languages('GET', user: $editor), fn () => $this->remove('GET', '/admin/settings/languages/remove?locale=pt-PT', user: $editor)] as $call) {
            try {
                $call();
                self::fail('editors can\'t change languages');
            } catch (HttpForbiddenException) {
                self::assertSame([], $this->events->events);
            }
        }
    }

    public function testListsTheLanguagesWithTheirAddressesAndOffersTheOthers(): void
    {
        $html = (string) $this->languages('GET')->getBody();

        self::assertStringContainsString('<title>Languages - Admin - Demo</title>', $html);
        self::assertStringContainsString('<th scope="row" lang="en-GB">British English</th>', $html);
        self::assertStringContainsString('<th scope="row" lang="pt-PT">European Portuguese</th>', $html);
        self::assertStringContainsString('<code>/pt-pt/</code>', $html);
        self::assertStringContainsString('Make European Portuguese the main language', $html);
        self::assertStringContainsString('href="/admin/settings/languages/remove?locale=pt-PT">Remove European Portuguese</a>', $html);
        self::assertStringContainsString('<option value="es">Spanish (es)</option>', $html);
        self::assertStringNotContainsString('<option value="pt-PT">', $html);
    }

    public function testAddsALanguage(): void
    {
        $response = $this->languages('POST', ['locale' => 'es']);

        self::assertSame([303, '/admin/settings/languages'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame('en-GB,pt-PT,es', $this->fixture->auth->database->settings()->get(SiteProfile::LOCALES));
        self::assertSame(['Added Spanish. Translate pages into it from each page’s editor.'], Flash::take($this->session));
    }

    public function testExplainsWhatIsWrongWithALanguageToAdd(): void
    {
        $missing = $this->languages('POST', ['locale' => '']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('Choose the language to add', (string) $missing->getBody());

        $unknown = $this->languages('POST', ['locale' => 'klingon']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame([], $this->events->events);
    }

    public function testExplainsWhenALanguageWasAddedMeanwhile(): void
    {
        $response = $this->languages('POST', ['locale' => 'pt-PT']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The site is already written in European Portuguese (pt-PT).', (string) $response->getBody());
    }

    public function testMakesAnotherLanguageTheMainOne(): void
    {
        $response = $this->languages('POST', ['main' => 'pt-PT']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('pt-PT', $this->fixture->auth->database->settings()->get(SiteProfile::DEFAULT_LOCALE));
        self::assertSame(['European Portuguese is now the main language. Its pages have addresses without a language part.'], Flash::take($this->session));

        $this->languages('POST', ['main' => 'en-GB'], languages: $this->languagesService(['en-GB', 'pt-PT']));
        self::assertSame(['Nothing was changed.'], Flash::take($this->session));
        $this->languages('POST', ['main' => 'fr-FR']);
        self::assertStringContainsString('The site isn’t written in “fr-FR”.', implode(' ', Flash::take($this->session)));
    }

    public function testAsksBeforeRemovingALanguageAndSaysWhatHappens(): void
    {
        $html = (string) $this->remove('GET', '/admin/settings/languages/remove?locale=pt-PT')->getBody();

        self::assertStringContainsString('<h1>Remove European Portuguese?</h1>', $html);
        self::assertStringContainsString('addresses starting with /pt-pt/ answer “not found”', $html);
        self::assertStringContainsString('get British English instead', $html);
        self::assertStringContainsString('<input type="hidden" name="locale" value="pt-PT">', $html);

        $response = $this->remove('POST', RemoveLanguageHandler::PATH, ['locale' => 'pt-PT']);
        self::assertSame([303, '/admin/settings/languages'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame('en-GB', $this->fixture->auth->database->settings()->get(SiteProfile::LOCALES));
        self::assertEquals(new LocaleRemoved('pt-PT', (int) $this->admin->id()), $this->events->events[1]);
    }

    public function testTheMainLanguageAndLanguagesTheSiteHasntGotCantBeRemoved(): void
    {
        $response = $this->remove('GET', '/admin/settings/languages/remove?locale=en-GB');
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('is the main language, so it can’t be removed', implode(' ', Flash::take($this->session)));

        $this->expectException(HttpNotFoundException::class);
        $this->remove('GET', '/admin/settings/languages/remove?locale=fr-FR');
    }

    /**
     * @param list<string> $locales
     */
    private function languagesService(array $locales = ['en-GB', 'pt-PT']): SiteLanguages
    {
        return new SiteLanguages(PageFixture::site($locales), $this->fixture->auth->database->settings(), $this->events);
    }

    /**
     * @param array<string, string> $body
     */
    private function languages(string $method, array $body = [], ?User $user = null, ?SiteLanguages $languages = null): ResponseInterface
    {
        $handler = new LanguagesHandler($this->fixture->shell(), $this->fixture->auth->validator(), $languages ?? $this->languagesService());

        return $handler->handle($this->fixture->request($user ?? $this->admin, $method, LanguagesHandler::PATH, $body, $this->session));
    }

    /**
     * @param array<string, string> $body
     */
    private function remove(string $method, string $path, array $body = [], ?User $user = null): ResponseInterface
    {
        return new RemoveLanguageHandler($this->fixture->shell(), $this->languagesService())
            ->handle($this->fixture->request($user ?? $this->admin, $method, $path, $body, $this->session));
    }
}
