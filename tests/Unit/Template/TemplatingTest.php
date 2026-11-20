<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Http\Security\GrantedPermissions;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\DateFormatter;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\TextDirection;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\ModuleRegistrar;
use Twig\Error\RuntimeError;

#[CoversClass(TwigFactory::class)]
#[CoversClass(TemplateExtension::class)]
#[CoversClass(TemplateRenderer::class)]
#[CoversClass(TemplatePaths::class)]
#[UsesClass(CurrentRequest::class)]
#[UsesClass(CspNonce::class)]
#[UsesClass(GrantedPermissions::class)]
#[UsesClass(AssetUrls::class)]
#[UsesClass(DateFormatter::class)]
#[UsesClass(Translations::class)]
#[UsesClass(Viewer::class)]
#[UsesClass(TextDirection::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Container\Services::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class TemplatingTest extends TestCase
{
    private TemporaryDirectory $directory;

    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $d = $this->directory;
        $d->write('core/page.twig', 'core page');
        $d->write('core/layout.twig', 'core layout');
        $d->write('core/footer.twig', 'core footer');
        $d->write('default/templates/layout.twig', 'default layout + {% include "footer.twig" %}');
        $d->write('default/templates/modules/acme/card.twig', 'default theme card');
        $d->write('site/templates/page.twig', 'site page');
        $d->write('module/card.twig', 'module card');
        $d->write('module/list.twig', 'module list');
        $d->write('functions.twig', <<<'TWIG'
            <p title="{{ title }}">{{ title }}</p>
            <link href="{{ asset('css/site.css') }}">
            <script nonce="{{ csp_nonce() }}"></script>
            {{ t('Hello, {name}', {name: name}) }}
            {% if can('page.publish') %}publish{% else %}no publish{% endif %}
            {{ when|user_date }} / {{ when|user_datetime('short') }}
            <html lang="{{ current_locale() }}" dir="{{ text_direction(current_locale()) }}"> {{ text_direction('ar') }}
            [{{ t(none) }}{{ t('') }}] {{ current_path() }}
            modules: {{ module_enabled('acme') ? 'acme' : 'no acme' }} {{ module_enabled('acme.other') ? 'other' : 'no other' }}
            {% macro nonce() %}{{ csp_nonce() }}{% endmacro %}{{ _self.nonce() }}
            TWIG);

        $this->request = new ServerRequest('GET', '/admin/pages?state=draft')
            ->withAttribute(CspNonce::class, new CspNonce('abc+/='))
            ->withAttribute(GrantedPermissions::class, new GrantedPermissions(['page.publish']))
            ->withAttribute(Viewer::class, new Viewer('en-GB', 'Europe/London'));
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTheSiteThemeWinsThenTheDefaultThemeThenCore(): void
    {
        $renderer = $this->renderer();

        self::assertSame('site page', $renderer->render($this->request, 'page.twig'));
        self::assertSame('default layout + core footer', $renderer->render($this->request, 'layout.twig'));
        self::assertSame('default theme card', $renderer->render($this->request, '@acme/card.twig'));
        self::assertSame('module list', $renderer->render($this->request, '@acme/list.twig'));
    }

    public function testFunctionsAndFiltersUseTheRequestAndOutputIsEscaped(): void
    {
        $html = $this->renderer(withFunctions: true)->render($this->request, 'functions.twig', [
            'title' => '<b>"Hi"</b>',
            'name' => 'Ada & Grace',
            'when' => new DateTimeImmutable('2026-07-01 23:30:00 UTC'),
            'none' => null,
        ]);

        self::assertStringContainsString('<p title="&lt;b&gt;&quot;Hi&quot;&lt;/b&gt;">&lt;b&gt;&quot;Hi&quot;&lt;/b&gt;</p>', $html);
        self::assertStringContainsString('<link href="/assets/css/site.css?v=1">', $html);
        self::assertStringContainsString('<script nonce="abc+/="></script>', $html);
        self::assertStringContainsString('Hello, Ada &amp; Grace', $html);
        self::assertStringContainsString('publish', $html);
        self::assertStringContainsString('2 July 2026 / 02/07/2026, 00:30', $html);
        self::assertStringContainsString('<html lang="en-GB" dir="ltr"> rtl', $html);
        self::assertStringContainsString('[] /admin/pages?state=draft', $html, 'nothing to translate is nothing');
        self::assertStringEndsWith('abc+/=', trim($html), 'csp_nonce() works inside macros');
        self::assertStringContainsString('modules: acme no other', $html);
    }

    public function testModuleEnabledFollowsTheSiteOfTheRequest(): void
    {
        $site = new \SightPress\Core\Tests\Support\ArrayContainer([ModuleRegistry::class => ModuleRegistry::register([])]);
        $request = $this->request->withAttribute(\SightPress\Core\Tenant\SiteContext::class, new \SightPress\Core\Tenant\SiteContext(\SightPress\Core\Tests\Support\SiteFixture::site(), $site));
        $html = $this->renderer(withFunctions: true)->render($request, 'functions.twig', [
            'title' => 't',
            'name' => 'n',
            'when' => new DateTimeImmutable('2026-07-01 23:30:00 UTC'),
            'none' => null,
        ]);
        self::assertStringContainsString('modules: no acme no other', $html, 'acme is off for this site');

        $extension = new TemplateExtension(new CurrentRequest(), new AssetUrls('/assets/', '1'), Translations::fromDirectories([]), new DateFormatter(), new Viewer('en-GB', 'UTC'));
        self::assertFalse($extension->moduleEnabled('acme'), 'outside a request and without the modules, nothing is on');
    }

    public function testAnonymousVisitorsGetTheDefaults(): void
    {
        $request = new ServerRequest('GET', '/')->withAttribute(CspNonce::class, new CspNonce('n'));

        $html = $this->renderer(withFunctions: true)->render($request, 'functions.twig', [
            'title' => 't',
            'name' => 'n',
            'when' => new DateTimeImmutable('2026-07-01 23:30:00 UTC'),
            'none' => null,
        ]);

        self::assertStringContainsString('no publish', $html);
        self::assertStringContainsString('1 July 2026 / 01/07/2026, 23:30', $html, 'site default time zone is UTC here');
    }

    public function testResponsesAreHtml(): void
    {
        $response = $this->renderer()->response($this->request, 'page.twig', [], 404);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('site page', (string) $response->getBody());
    }

    public function testInDebugModeUnknownVariablesAreErrors(): void
    {
        $this->directory->write('core/typo.twig', '{{ titel }}');

        self::assertSame('', $this->renderer()->render($this->request, 'typo.twig'));

        $this->expectException(RuntimeError::class);
        $this->renderer(debug: true)->render($this->request, 'typo.twig');
    }

    public function testCompiledTemplatesAreCachedOutsideDebugMode(): void
    {
        $this->renderer()->render($this->request, 'page.twig');

        self::assertDirectoryExists($this->directory->path . '/cache/twig');
        self::assertSame(dirname(__DIR__, 3) . '/resources/views', TemplatePaths::core());
        self::assertSame(new \SightPress\ThemeDefault\DefaultTheme()->paths()->root, TemplatePaths::defaultTheme());
        self::assertSame(dirname(__DIR__, 3) . '/resources/assets', TemplatePaths::coreAssets());
    }

    private function renderer(bool $debug = false, bool $withFunctions = false): TemplateRenderer
    {
        $d = $this->directory->path;
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: static function (ModuleRegistrar $r) use ($d): void {
                $r->views()->add('acme', $d . '/module');
            }), 'acme/module'),
        ])->resolve(new ModuleSelection()));
        $current = new CurrentRequest();
        $extension = new TemplateExtension(
            $current,
            new AssetUrls('/assets/', '1'),
            Translations::fromDirectories([]),
            new DateFormatter(),
            new Viewer('en-GB', 'UTC'),
            $registry,
        );
        $paths = $withFunctions
            ? new TemplatePaths([], $d)
            : new TemplatePaths([$d . '/site', $d . '/default', $d . '/missing'], $d . '/core');
        $twig = new TwigFactory()->create($paths, $registry, $extension, $d . '/cache', $debug);
        $factory = new Psr17Factory();

        return new TemplateRenderer($twig, $current, $factory, $factory);
    }
}
