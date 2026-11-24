<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Admin\ModuleScreens;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Contract\PublicScreenRendering;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Http\AdminScreen;
use SightPress\Module\Http\PublicScreen;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Text\Message;

#[CoversClass(ModuleScreens::class)]
#[UsesNamespace('SightPress\Core')]
final class ModuleScreensTest extends TestCase
{
    private AdminFixture $admin;

    private TestProject $project;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAdminScreenInTheShellWithATranslatedTitle(): void
    {
        $user = $this->admin->auth->user('ana@example.org', role: 'admin');
        $request = $this->admin->request($user, path: '/admin/events')->withAttribute(ViewerPreferences::class, new ViewerPreferences('pt-PT', null));

        $response = $this->screens()->admin($request, new AdminScreen(new Message('Site settings'), '@admin/server-problems.html.twig', ['server_problems' => []], 422));
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<h1>Definições do site</h1>', $html);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        $plain = (string) $this->screens()->admin($this->admin->request($user, path: '/admin/events'), new AdminScreen('Events', '@admin/server-problems.html.twig'))->getBody();
        self::assertStringContainsString('<h1>Events</h1>', $plain);
    }

    public function testRedirectKeepsANoticeAndRefusesOtherSites(): void
    {
        $session = new Session();
        $request = $this->admin->request(null, session: $session);

        $response = $this->screens()->redirect($request, '/admin/events', new Message('Added “{title}”.', ['title' => 'Open day']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/events', $response->getHeaderLine('Location'));
        self::assertSame(['Added “Open day”.'], array_map(static fn ($message): string => is_string($message) ? $message : str_replace('{title}', 'Open day', $message->text), Flash::take($session)));
        self::assertSame(303, $this->screens()->redirect($request, '/admin', 'Saved.')->getStatusCode());
        self::assertSame(303, $this->screens()->redirect($request, '/admin')->getStatusCode());

        $this->expectException(\InvalidArgumentException::class);
        $this->screens()->redirect($request, '//evil.example/');
    }

    public function testPublicScreenInTheThemeWithItsSeoEntry(): void
    {
        $seo = new SeoEntry('/events/open-day', 'en-GB', 'Open day', new DateTimeImmutable('2026-11-24T10:00:00Z'), 'An open day.', '/events/open-day.md', ['pt-PT' => '/pt-pt/eventos/dia-aberto', 'fr' => '/fr/x'], [['@type' => 'Event', 'name' => 'Open day']]);
        $request = $this->admin->request(null, path: '/events/open-day')->withAttribute(ViewerPreferences::class, new ViewerPreferences(null, 'Europe/Lisbon'));

        $response = $this->screens(['en-GB', 'pt-PT'])->public($request, new PublicScreen('Open day', '@admin/server-problems.html.twig', seo: $seo));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertSame('', $response->getHeaderLine('X-Robots-Tag'), 'production, public, indexable');
        self::assertStringContainsString('<title>Open day - ', $html);
        self::assertStringContainsString('<meta name="description" content="An open day.">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://demo.example/events/open-day">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="pt-PT" href="https://demo.example/pt-pt/eventos/dia-aberto">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://demo.example/events/open-day">', $html);
        self::assertStringNotContainsString('hreflang="fr"', $html, 'only the site\'s languages');
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://demo.example/events/open-day.md">', $html);
        self::assertStringContainsString('"@graph":[{"@type":"WebPage"', $html);
        self::assertStringContainsString('{"@type":"Event","name":"Open day"}', $html);
        self::assertInstanceOf(PublicScreenRendering::class, $this->dispatched[0], 'core\'s modules add menus and branding');
    }

    public function testPublicScreenWithoutSeoInTheDefaultLanguage(): void
    {
        $seo = new SeoEntry('/x', 'he', 'X', new DateTimeImmutable());
        $request = $this->admin->request(null, path: '/events/nothing');

        $missing = $this->screens()->public($request, new PublicScreen(new Message('Page not found'), '@admin/server-problems.html.twig', status: 404));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('noindex', $missing->getHeaderLine('X-Robots-Tag'));
        self::assertStringNotContainsString('rel="canonical"', (string) $missing->getBody());

        $other = $this->screens()->public($request, new PublicScreen('X', '@admin/server-problems.html.twig', seo: $seo));
        self::assertSame('en-GB', $other->getHeaderLine('Content-Language'), 'a language the site doesn\'t have falls back to its default');
        self::assertStringNotContainsString('hreflang', (string) $other->getBody());
        self::assertStringNotContainsString('name="description"', (string) $other->getBody());
        self::assertSame('noindex, nofollow', $this->screens(environment: 'test')->public($request, new PublicScreen('X', '@admin/server-problems.html.twig', noindex: true))->getHeaderLine('X-Robots-Tag'));
    }

    public function dispatched(object $event): void
    {
        $this->dispatched[] = $event;
    }

    /**
     * @param list<string> $locales
     */
    private function screens(array $locales = ['en-GB'], string $environment = 'production'): ModuleScreens
    {
        $base = $this->admin->auth->site;
        $site = new Site($base->id, $base->slug, $base->name, $base->status, $base->ownerType, $base->domains, $base->databaseDsn, $base->storage, $locales[0], $locales, $base->defaultTimezone, new DateTimeImmutable());
        $events = new class ($this) implements EventDispatcherInterface {
            public function __construct(private ModuleScreensTest $test)
            {
            }

            public function dispatch(object $event): object
            {
                $this->test->dispatched($event);

                return $event;
            }
        };

        return new ModuleScreens(
            $this->admin->shell(),
            $this->admin->auth->templates,
            $this->admin->auth->translations,
            $events,
            $this->admin->auth->http,
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment]),
            $site,
        );
    }
}
