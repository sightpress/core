<?php

/**
 * A real site for the accessibility tests of the starter content (C-6.4): the whole kernel with core's modules, and a
 * site made with `site:create` (an organization, domain 127.0.0.1, SQLite file), so it has the starter pages and
 * menus every new site gets, in production mode (search engines welcome). Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * `/_test/reset` starts again from a fresh site.
 *
 * With SIGHTPRESS_A11Y_SITE=translated (a second copy, for translations.spec.ts) the site is also written in European
 * Portuguese and Hebrew (right to left): the home page and "About us" in Portuguese, "About us" in Hebrew, every
 * translation published.
 */

declare(strict_types=1);

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';

$copy = getenv('SIGHTPRESS_A11Y_SITE') === 'translated' ? 'translated' : 'starter';
$project = sys_get_temp_dir() . '/sightpress-a11y-' . $copy;
$port = $copy === 'translated' ? 8084 : 8091;
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
// Production, as a real site: pages may be indexed, so Lighthouse can check them for search engines.
$environment = ['APP_ENV' => 'production', 'APP_SECRET' => SECRET];

if ($path === '/_test/reset') {
    exec('rm -rf ' . escapeshellarg($project));
    http_response_code(204);

    return;
}

if (!is_file($project . '/config/sites.php')) {
    @mkdir($project . '/config', 0o777, true);
    file_put_contents($project . '/config/tenant.php', '<?php return ' . var_export([
        'log' => ['path' => $project . '/var/log/test.log'],
        'session' => ['path' => $project . '/var/sessions'],
    ], true) . ';');
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 2, 'sites' => []], true) . ';');
    // The registry of the site before the reset may still be in OPcache.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($project . '/config/sites.php', true);
    }
    $console = Kernel::boot($project, $environment)->console();
    $console->setAutoExit(false);
    $status = $console->run(new ArrayInput([
        'command' => 'site:create',
        'slug' => 'demo',
        '--domain' => '127.0.0.1',
        '--db' => 'sqlite:///' . $project . '/demo.sqlite',
        '--owner-type' => 'organization',
        '--locale' => 'en-GB',
        '--name' => 'Riverside Library',
        '--url' => 'http://127.0.0.1:' . $port,
        '--admin-email' => 'ada@example.org',
        '--admin-first-name' => 'Ada',
        '--admin-last-name' => 'Lovelace',
    ]), $output = new BufferedOutput());
    if ($status !== 0) {
        throw new RuntimeException('site:create failed: ' . $output->fetch());
    }
    if ($copy === 'translated') {
        translate($project, $environment);
    }
}

Kernel::boot($project, $environment)->run();

/**
 * Adds Portuguese and Hebrew and publishes the translations (see the top of the file).
 *
 * @param array<string, string> $environment
 */
function translate(string $project, array $environment): void
{
    foreach (['pt-PT', 'he'] as $locale) {
        $console = Kernel::boot($project, $environment)->console();
        $console->setAutoExit(false);
        if ($console->run(new ArrayInput(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => $locale]), $output = new BufferedOutput()) !== 0) {
            throw new RuntimeException('site:locale:add failed: ' . $output->fetch());
        }
    }
    $kernel = Kernel::boot($project, $environment);
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');
    $services = Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    $pages = Services::get($services, PageRepository::class);
    $editor = Services::get($services, PageEditor::class);
    $text = static fn (string $html): array => [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]]];
    $home = Services::get($services, PageAddresses::class)->home() ?? throw new LogicException('No home page.');
    $about = $pages->findByPath('en-GB', 'about')?->page() ?? throw new LogicException('No about page.');
    foreach ([
        [$home, 'pt-PT', new PageContent('Bem-vindo à Riverside Library', 'inicio', 'A biblioteca junto ao rio.', 'A biblioteca junto ao rio: livros, música e grupos de leitura para todas as idades.', $text('<p>Venha ler connosco, todos os dias da semana.</p>'))],
        [$about, 'pt-PT', new PageContent('Sobre nós', 'sobre', 'Quem somos e o que fazemos.', 'Quem somos: a biblioteca junto ao rio, os seus livros, a sua música e as pessoas que cuidam dela.', $text('<p>Cuidamos dos livros, da música e da vista do porto.</p>'))],
        [$about, 'he', new PageContent('אודותינו', 'odot', 'מי אנחנו ומה אנחנו עושים.', 'הספרייה שליד הנהר: ספרים, מוזיקה וקבוצות קריאה לכל הגילים, בכל יום בשבוע.', $text('<p>אנחנו שומרים על הספרים, על המוזיקה ועל הנוף של הנמל.</p>'))],
    ] as [$page, $locale, $content]) {
        $editor->save($page, $locale, $content);
        $editor->publishTranslation($page, $locale, true);
    }
}
