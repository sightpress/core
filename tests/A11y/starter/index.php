<?php

/**
 * A real site for the accessibility tests of the starter content (C-6.4): the whole kernel with core's modules, and a
 * site made with `site:create` (an organization, domain 127.0.0.1, SQLite file), so it has the starter pages and
 * menus every new site gets, in production mode (search engines welcome). Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * `/_test/reset` starts again from a fresh site.
 */

declare(strict_types=1);

use SightPress\Core\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';

$project = sys_get_temp_dir() . '/sightpress-a11y-starter';
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
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => []], true) . ';');
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
        '--url' => 'http://127.0.0.1:8091',
        '--admin-email' => 'ada@example.org',
        '--admin-first-name' => 'Ada',
        '--admin-last-name' => 'Lovelace',
    ]), $output = new BufferedOutput());
    if ($status !== 0) {
        throw new RuntimeException('site:create failed: ' . $output->fetch());
    }
}

Kernel::boot($project, $environment)->run();
