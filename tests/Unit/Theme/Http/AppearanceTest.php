<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\AuditRecorder;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\Contract\ThemeColoursChanged;
use SightPress\Core\Theme\Http\AppearanceHandler;
use SightPress\Core\Theme\Http\ThemeColoursHandler;
use SightPress\Core\Theme\PublishedPreviews;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeColours;
use SightPress\Core\Theme\ThemeModule;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The admin's theme picker and colours screen, through the kernel, as an admin and as an editor.
 */
#[CoversClass(AppearanceHandler::class)]
#[CoversClass(ThemeColoursHandler::class)]
#[CoversClass(PublishedPreviews::class)]
#[CoversClass(ThemeColoursChanged::class)]
#[CoversClass(ThemeModule::class)]
#[CoversClass(\SightPress\Core\Theme\ThemeChanged::class)]
#[CoversClass(AuditRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class AppearanceTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject(['themes' => ['allowed' => ['default', 'acme-harbour']]]);
        $this->project->directory->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]],
        ]]));
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        $application = $this->kernel->console();
        $application->setAutoExit(false);
        self::assertSame(0, new ApplicationTester($application)->run([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'example.org',
            '--db' => 'sqlite:///' . $this->project->path() . '/sites/demo.sqlite',
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ], ['interactive' => false]));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsTheInstalledThemesAndAppliesOne(): void
    {
        $this->project->directory->write('public/themes/acme-harbour/1.2.0/preview/index.html', '<!DOCTYPE html>');
        $this->project->directory->write('public/themes/acme-harbour/1.2.0/preview/screenshot.png', 'png');
        $admin = $this->signedIn('ada@example.org');

        $html = (string) $admin->request('GET', '/admin/appearance')->getBody();
        self::assertStringContainsString('<h1>Appearance</h1>', $html);
        self::assertStringContainsString('<h2 id="theme-default" tabindex="-1">SightPress Default</h2>', $html);
        self::assertStringContainsString('The site’s theme</p>', $html);
        self::assertStringContainsString('<strong>What it looks like:</strong> A light grey top bar', $html, 'no screenshot: the description in words');
        self::assertStringContainsString('<img class="theme-choice__screenshot" src="/themes/acme-harbour/1.2.0/preview/screenshot.png" alt="A dark blue top bar and a large white heading."', $html);
        self::assertStringContainsString('<a href="/themes/acme-harbour/1.2.0/preview/index.html" target="_blank" rel="noopener">Preview Harbour (opens in a new tab)</a>', $html);
        self::assertStringContainsString('>Use Harbour</button>', $html);
        self::assertStringNotContainsString('>Use SightPress Default</button>', $html);
        self::assertStringContainsString('href="/admin/appearance"', $html, 'in the admin menu');

        $admin->submit('/admin/appearance', ['theme' => 'acme-harbour']);
        $html = (string) $admin->request('GET', '/admin/appearance')->getBody();
        self::assertStringContainsString('Harbour is now the site’s theme.', $html);
        self::assertStringContainsString('>Use SightPress Default</button>', $html);
        self::assertSame('acme-harbour', Services::get($this->site(), SiteTheme::class)->chosen());

        $admin->submit('/admin/appearance', ['theme' => 'acme-gone']);
        self::assertStringContainsString('The theme can’t be used: The theme &quot;acme-gone&quot; is not installed.', (string) $admin->request('GET', '/admin/appearance')->getBody());
    }

    public function testChangesTheColoursAfterCheckingTheirContrastAndResetsThem(): void
    {
        $admin = $this->signedIn('ada@example.org');
        $html = (string) $admin->request('GET', '/admin/appearance/colours')->getBody();
        self::assertStringContainsString('<legend>Light mode</legend>', $html);
        self::assertStringContainsString('name="light__primary"', $html);
        self::assertStringNotContainsString('Use the theme’s own colours', $html);

        $values = [];
        foreach (ThemeColoursHandler::form()->fields() as $field) {
            preg_match('/value="([^"]*)"[^>]*name="' . $field->name . '"/', $html, $value);
            $values[$field->name] = $value[1] ?? '';
        }

        $refused = $admin->submit('/admin/appearance/colours', ['light__primary' => '#a0c4ff'] + $values);
        self::assertSame(422, $refused->getStatusCode());
        $body = (string) $refused->getBody();
        self::assertStringContainsString('on-primary on primary has a contrast of', $body);
        self::assertStringContainsString(':1 in light mode; it needs at least 4.5:1. Choose a darker or lighter colour.', $body);
        self::assertStringContainsString('value="#a0c4ff"', $body, 'the form keeps what was typed');

        $dark = $admin->submit('/admin/appearance/colours', ['dark__text' => '#333333'] + $values);
        self::assertStringContainsString(':1 in dark mode; it needs at least 4.5:1.', (string) $dark->getBody());

        $invalid = $admin->submit('/admin/appearance/colours', ['dark__link' => 'blue'] + $values);
        self::assertStringContainsString('Write the colour as # and six digits or letters from a to f', (string) $invalid->getBody());

        $saved = $admin->submit('/admin/appearance/colours', ['light__primary' => '#004080'] + $values);
        self::assertSame(303, $saved->getStatusCode());
        $html = (string) $admin->request('GET', '/admin/appearance/colours')->getBody();
        self::assertStringContainsString('The colours are saved.', $html);
        self::assertStringContainsString('The site uses some colours of its own.', $html);
        self::assertStringContainsString('value="#004080"', $html);
        self::assertSame(['light' => ['primary' => '#004080'], 'dark' => []], Services::get($this->site(), ThemeColours::class)->overrides('default'));
        $tokens = (string) new Browser($this->kernel, 'http://example.org')->request('GET', '/assets/tokens.css')->getBody();
        self::assertStringContainsString('#004080', $tokens);
        $adminTokens = (string) new Browser($this->kernel, 'http://example.org')->request('GET', '/assets/admin/tokens.css')->getBody();
        self::assertStringNotContainsString('#004080', $adminTokens, 'the admin keeps its own colours');

        $admin->submit('/admin/appearance/colours', ['reset' => '1']);
        self::assertStringContainsString('The site uses the colours of SightPress Default again.', (string) $admin->request('GET', '/admin/appearance/colours')->getBody());
        self::assertSame(['light' => [], 'dark' => []], Services::get($this->site(), ThemeColours::class)->overrides('default'));

        $entries = Services::get($this->site(), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertSame(['theme.colours_reset', 'theme.colours_changed'], [$entries[0]->action, $entries[1]->action]);
        self::assertSame(['colours' => ['light.primary']], $entries[1]->details);
        self::assertSame('Went back to the colours of the theme SightPress Default.', $entries[0]->summary);
    }

    public function testOnlyPeopleWhoApplyThemesMayUseTheScreens(): void
    {
        $editor = $this->signedIn('bea@example.org', 'editor');

        self::assertSame(403, $editor->request('GET', '/admin/appearance')->getStatusCode());
        self::assertSame(403, $editor->request('GET', '/admin/appearance/colours')->getStatusCode());
    }

    private function site(): ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);
        $factory = Services::get($this->kernel->container(), SiteContainerFactory::class);
        $factory->reset();

        return $factory->forSite($site);
    }

    private function signedIn(string $email, string $role = 'admin'): Browser
    {
        $container = $this->site();
        $users = Services::get($container, UserRepository::class);
        $user = $users->findByEmail($email) ?? new User($email, 'Bea', 'Okafor', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));

        $browser = new Browser($this->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
