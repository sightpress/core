<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Admin\Http\SettingsHandler;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\Entity\User;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(SettingsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class SettingsHandlerTest extends TestCase
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

    public function testOnlyPeopleWhoManageSettingsMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->handle('GET', [], user: $this->fixture->auth->user('bea@example.org', 'editor'));
    }

    public function testShowsTheSiteAndEveryModulesSettings(): void
    {
        $html = (string) $this->handle('GET')->getBody();

        self::assertStringContainsString('<title>Site settings - Admin - Demo</title>', $html);
        self::assertStringContainsString('id="site-settings-name" value="Demo"', $html);
        self::assertStringContainsString('<legend>Who runs the site</legend>', $html);
        self::assertStringContainsString('<legend>Search &amp; AI</legend>', $html);
        self::assertStringContainsString('name="setting__site__contact_email"', $html);
        self::assertStringContainsString('value="allow_all" checked', $html);
        self::assertStringContainsString('Language: English (United Kingdom). More languages can be added from the command line.', $html);
        self::assertStringContainsString('The site is run by an organisation.', $html);
        self::assertStringContainsString('<a href="/admin/settings/branding">Branding</a>', $html);
        self::assertStringContainsString('Public: anyone can read the published pages.', $html);
    }

    public function testSavesWhatChangedAndSaysSo(): void
    {
        $response = $this->handle('POST', $this->body([
            'name' => 'Riverside Reading Room',
            'timezone' => 'Asia/Tokyo',
            'setting__site__contact_email' => 'hello@example.org',
            'setting__site__same_as' => "https://example.social/@riverside\n",
            'setting__seo__ai_crawlers' => 'search_only',
            'setting__seo__verify_google' => 'abc_123',
            'setting__seo__indexnow' => '1',
            'setting__acme__size' => '12',
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['The settings have been saved.'], Flash::take($this->session));
        $settings = $this->fixture->auth->database->settings();
        self::assertSame(['Riverside Reading Room', 'Asia/Tokyo', 'hello@example.org', 'search_only', '1', '12'], [
            $settings->get(SiteProfile::NAME), $settings->get(SiteProfile::DEFAULT_TIMEZONE), $settings->get(SiteProfile::EMAIL),
            $settings->get('seo.ai_crawlers'), $settings->get('seo.indexnow'), $settings->get('acme.size'),
        ]);
        $event = $this->events->events[0] ?? null;
        self::assertInstanceOf(SettingsChanged::class, $event);
        self::assertSame([SiteProfile::NAME, SiteProfile::DEFAULT_TIMEZONE, 'site.contact_email', 'site.same_as', 'seo.ai_crawlers', 'seo.verify_google', 'seo.indexnow', 'acme.size'], $event->keys);
        self::assertSame($this->admin->id(), $event->actorId);

        $again = $this->handle('POST', $this->body(['setting__seo__indexnow' => '']));
        self::assertSame(303, $again->getStatusCode());
        self::assertSame('0', $settings->get('seo.indexnow'));
        $this->handle('POST', $this->body([]));
        self::assertSame(['The settings have been saved.', 'Nothing was changed.'], Flash::take($this->session));
    }

    public function testASiteInSeveralLanguagesChoosesItsMainOne(): void
    {
        $site = PageFixture::site(['en-GB', 'pt-PT']);
        self::assertStringContainsString('Main language', (string) $this->handle('GET', site: $site)->getBody());

        $this->handle('POST', $this->body(['default_locale' => 'pt-PT']), site: $site);

        self::assertSame('pt-PT', $this->fixture->auth->database->settings()->get(SiteProfile::DEFAULT_LOCALE));
    }

    public function testExplainsWhatIsWrong(): void
    {
        $response = $this->handle('POST', $this->body([
            'name' => '',
            'setting__site__contact_email' => 'not an email',
            'setting__site__same_as' => "https://ok.example/a\nhttp://insecure.example",
            'setting__seo__verify_bing' => '<meta name="msvalidate.01" content="x">',
            'setting__acme__size' => 'twelve',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('<title>5 errors: Site settings', $html);
        self::assertStringContainsString('Enter an email address in the right format, like name@example.com', $html);
        self::assertStringContainsString('“http://insecure.example” is not a full web address starting with https://.', $html);
        self::assertStringContainsString('Paste only the code', $html);
        self::assertStringContainsString('Enter a whole number, such as 10', $html);
        self::assertSame([], $this->events->events);
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function body(array $changes): array
    {
        return $changes + [
            'name' => 'Demo',
            'timezone' => 'Europe/London',
            'setting__seo__ai_crawlers' => 'allow_all',
            'setting__acme__size' => '',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handle(string $method, array $body = [], ?User $user = null, ?Site $site = null): ResponseInterface
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $catalogue = new SettingCatalogue([
            ...SettingCatalogue::fromRegistry($registry)->all(),
            ['module' => 'acme', 'setting' => new Setting('acme.size', 'Size', SettingType::Integer)],
        ]);
        $handler = new SettingsHandler(
            $this->fixture->shell(),
            $this->fixture->auth->validator(),
            $catalogue,
            $this->fixture->auth->database->settings(),
            $site ?? $this->fixture->auth->site,
            $this->events,
        );

        return $handler->handle($this->fixture->request($user ?? $this->admin, $method, '/admin/settings', $body, $this->session));
    }
}
