<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Site;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Site languages through the whole kernel (I-1): an admin adds Spanish, translates a page into it from English (a
 * draft nobody else sees), publishes the translation, removes Spanish (its page answers 404 and leaves the sitemap,
 * the person who chose Spanish gets English) and adds it again, which brings the translation back.
 */
#[CoversNothing]
final class SiteLanguagesFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private int $about;

    protected function setUp(): void
    {
        $this->site = new KernelSite('languages');
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        $editor = Services::get($container, PageEditor::class);
        $home = $editor->create('en-GB', new PageContent('Welcome', 'welcome', 'Hello.', 'The home page.'));
        $about = $editor->create('en-GB', new PageContent('About us', 'about', 'Who we are.', 'All about us.', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Our story', 'level' => 2]],
        ]));
        $home->changeState(PageState::Published, $now);
        $about->changeState(PageState::Published, $now);
        Services::get($container, PageRepository::class)->flush();
        $this->about = (int) $about->id();
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', $now);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('admin') ?? throw new \LogicException('admin'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnAdminAddsTranslatesRemovesAndAddsBackALanguage(): void
    {
        $ada = new Browser($this->site->kernel, 'https://example.org');
        $ada->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        $visitor = new Browser($this->site->kernel, 'https://example.org');

        self::assertSame(303, $ada->submit('/admin/settings/languages', ['locale' => 'es'])->getStatusCode());
        self::assertStringContainsString('Added Spanish.', (string) $ada->request('GET', '/admin/settings/languages')->getBody());
        self::assertStringContainsString('Translate into Spanish from British English', (string) $ada->request('GET', '/admin/pages/' . $this->about)->getBody());

        $started = $ada->submit('/admin/pages/' . $this->about, ['op' => 'translate', 'locale' => 'es', 'from' => 'en-GB'], '/admin/pages/' . $this->about . '/translations');
        self::assertSame('/admin/pages/' . $this->about . '?locale=es', $started->getHeaderLine('Location'));
        self::assertSame(404, $visitor->request('GET', '/es/about')->getStatusCode(), 'a draft translation is not public');
        self::assertSame(200, $ada->request('GET', '/es/about')->getStatusCode(), 'its editors can look at it');
        self::assertStringNotContainsString('/es/about', (string) $visitor->request('GET', '/sitemap.xml')->getBody());

        $ada->submit('/admin/pages/' . $this->about . '?locale=es', ['op' => 'publish', 'locale' => 'es'], '/admin/pages/' . $this->about . '/translations');
        self::assertSame(200, $visitor->request('GET', '/es/about')->getStatusCode());
        self::assertStringContainsString('hreflang="es"', (string) $visitor->request('GET', '/about')->getBody());
        self::assertStringContainsString('<loc>https://example.org/es/about</loc>', (string) $visitor->request('GET', '/sitemap.xml')->getBody());

        $users = Services::get($this->site->container(), UserRepository::class);
        $bea = new User('bea@example.org', 'Bea', 'Ruiz', 'UTC', new DateTimeImmutable(), locale: 'es');
        $users->save($bea);
        $confirm = (string) $ada->request('GET', '/admin/settings/languages/remove?locale=es')->getBody();
        self::assertStringContainsString('Remove Spanish?', $confirm);
        self::assertSame(303, $ada->submit('/admin/settings/languages/remove?locale=es', ['locale' => 'es'], '/admin/settings/languages/remove')->getStatusCode());
        self::assertSame(404, $visitor->request('GET', '/es/about')->getStatusCode());
        self::assertStringNotContainsString('/es/about', (string) $visitor->request('GET', '/sitemap.xml')->getBody());
        self::assertStringNotContainsString('hreflang="es"', (string) $visitor->request('GET', '/about')->getBody());
        self::assertNull(Services::get($this->site->container(), UserRepository::class)->findByEmail('bea@example.org')?->locale());

        $ada->submit('/admin/settings/languages', ['locale' => 'es']);
        self::assertSame(200, $visitor->request('GET', '/es/about')->getStatusCode(), 'the translation is back');

        $audit = (string) $ada->request('GET', '/admin/audit')->getBody();
        self::assertStringContainsString('Published the Spanish translation of “About us”.', $audit);
        self::assertStringContainsString('Changed the site settings: Languages.', $audit);
    }
}
