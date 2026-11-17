<?php

/**
 * The demo site for the crawl (C-8.1): the whole kernel with core's modules and a site made with
 * `site:create --with-demo-content` (an organization, domain 127.0.0.1, SQLite file) in production mode, so it has
 * the starter pages and menus, a page with every kind of block and its media, and an account for each role.
 * Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * Test-only shortcut: instead of typing the printed setup codes, every account (Ada the admin, Bea the editor, Cleo
 * the contributor and Max the member) gets the password "correct horse battery staple".
 *
 * `/_test/reset` starts again from a fresh site.
 */

declare(strict_types=1);

use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'correct horse battery staple';

$project = sys_get_temp_dir() . '/sightpress-a11y-demo';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
// Production, as a real site: pages may be indexed.
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
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => []], true) . ';');
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($project . '/config/sites.php', true);
    }
    $kernel = Kernel::boot($project, $environment);
    $console = $kernel->console();
    $console->setAutoExit(false);
    $status = $console->run(new ArrayInput([
        'command' => 'site:create',
        'slug' => 'demo',
        '--domain' => '127.0.0.1',
        '--db' => 'sqlite:///' . $project . '/demo.sqlite',
        '--owner-type' => 'organization',
        '--locale' => 'en-GB',
        '--name' => 'Riverside Library',
        '--url' => 'http://127.0.0.1:8087',
        '--admin-email' => 'ada@example.org',
        '--admin-first-name' => 'Ada',
        '--admin-last-name' => 'Lovelace',
        '--with-demo-content' => true,
    ]), $output = new BufferedOutput());
    if ($status !== 0) {
        throw new RuntimeException('site:create failed: ' . $output->fetch());
    }
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');
    $users = Services::get(Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site), UserRepository::class);
    $hash = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(PASSWORD);
    foreach (['ada', 'bea', 'cleo', 'max'] as $name) {
        $user = $users->findByEmail($name . '@example.org') ?? throw new LogicException($name . ' is missing.');
        $user->setPasswordHash($hash, new DateTimeImmutable());
        $users->save($user);
    }
}

Kernel::boot($project, $environment)->run();
