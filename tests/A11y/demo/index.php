<?php

/**
 * The demo site for the crawl (C-8.1): the whole kernel with core's modules and a site made with
 * `site:create --with-demo-content` (an organization, domain 127.0.0.1, SQLite file) in production mode, so it has
 * the starter pages and menus, a page with every kind of block and its media, and an account for each role.
 * Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * Test-only shortcut: instead of typing the printed setup codes, every account (Ada the admin, Bea the editor, Cleo
 * the contributor and Max the member) gets the password "Correct horse battery staple 7!".
 *
 * `/_test/reset` starts again from a fresh site.
 *
 * Copies: the environment variable SIGHTPRESS_A11Y_SITE names a copy with its own folder (default `demo`, the crawl's
 * site on port 8087, written in en-GB, pt-PT and he; `journeys` on port 8086 for tests/A11y/journeys.spec.ts, in en-GB
 * and pt-PT; `search` on port 8074 for tests/A11y/search.spec.ts; `members` on port 8073, a members-only site for the
 * crawl; `themed` on port 8064, on a second installed theme; `cloud` on port 8063, with its files in MinIO and a CDN
 * address; `mediajourney` on port 8062 for tests/A11y/journeys-themes-media.spec.ts; `embeds` on port 8056 for
 * tests/A11y/blocks-m4.spec.ts; `extended` on port 8055 for the M4 crawl, tests/A11y/crawl-m4.spec.ts; `extjourney` on
 * port 8054 for the M4 journeys, tests/A11y/journeys-m4.spec.ts), so specs never reset each other's site.
 *
 * The M4 copies (`extended`, `extjourney`) are a tenant of an organisation that lets people at example.org register on
 * its members-only sites, with text messages through the pretend "Test SMS" service of a test package (nothing leaves
 * the server; `/_test/texts?to=+447…` answers with the latest text to that number). They have a second, members-only
 * site, "Riverside Staff", on `localhost` at the same port, with text messages off. On `extended` the fixture
 * module package `acme/events` is installed and on for the demo site (with one event), a custom role "Events team"
 * exists and two-step confirmation is on, with confirmed numbers for Ada and Max. On `extjourney` nothing is on yet:
 * `/_test/journey/install-module` and `/_test/journey/remove-module` add and remove the package, as Composer would,
 * and `/_test/journey/phone-reset?email=…` runs `site:user:phone:reset`. Sign-up is open on `demo`, `journeys` and the M4 copies. Its search index is built after site:create.
 * The site's address uses the port it is served on.
 *
 * `/_test/schedule` runs `schedule:run` once and answers with what it printed.
 *
 * Test mailbox: instead of sending email, the site keeps every message in its folder. `/_test/mail?to=…` answers
 * with the latest message to that address as JSON (subject, text and HTML body), or 404 when there is none.
 */

declare(strict_types=1);

use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Kernel;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mime\Email;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'Correct horse battery staple 7!';
// The skeleton's MinIO service with its development keys.
const S3_ENDPOINT = 'http://127.0.0.1:9000';
const S3_KEY = 'sightpress';
const S3_SECRET = 'sightpress-secret';

/**
 * Connects the demo site to a new folder of the MinIO bucket and moves its files there.
 *
 * @param callable(array<string, mixed>): void $command runs a console command, and throws when it fails
 */
function moveToBucket(callable $command, string $prefix): void
{
    $bucket = 'sightpress-a11y';
    $s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'eu-west-2', 'endpoint' => S3_ENDPOINT, 'use_path_style_endpoint' => true, 'credentials' => ['key' => S3_KEY, 'secret' => S3_SECRET]]);
    if (!$s3->doesBucketExistV2($bucket)) {
        $s3->createBucket(['Bucket' => $bucket]);
    }
    putenv('SIGHTPRESS_S3_ACCESS_KEY=' . S3_KEY);
    putenv('SIGHTPRESS_S3_SECRET_KEY=' . S3_SECRET);
    $command(['command' => 'site:storage:s3', 'slug' => 'demo', '--bucket' => $bucket, '--region' => 'eu-west-2', '--endpoint' => S3_ENDPOINT, '--prefix' => $prefix . '-' . bin2hex(random_bytes(4)), '--path-style' => true]);
    $command(['command' => 'site:storage:migrate', 'slug' => 'demo', '--delete-source' => true]);
}

/**
 * Lists the M4 test packages in the project's `vendor/composer/installed.json`, as Composer would after `composer
 * require`: the pretend text message service always, and the fixture module `acme/events` when asked.
 */
function installPackages(string $project, bool $events): void
{
    $packages = [[
        'name' => 'acme/test-sms',
        'type' => 'sightpress-module',
        'require' => ['sightpress/module-interface' => '^0.5'],
        'extra' => ['sightpress' => ['module' => SightPress\Core\Tests\Support\Modules\TestSmsModule::class]],
    ]];
    if ($events) {
        $packages[] = [
            'name' => 'acme/events',
            'type' => 'sightpress-module',
            'require' => ['sightpress/module-interface' => '^0.5'],
            'extra' => ['sightpress' => ['module' => Acme\Events\EventsModule::class]],
        ];
    }
    @mkdir($project . '/vendor/composer', 0o777, true);
    file_put_contents($project . '/vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
}

$copy = getenv('SIGHTPRESS_A11Y_SITE');
$copy = is_string($copy) && preg_match('/^[a-z]+$/', $copy) === 1 ? $copy : 'demo';
$project = sys_get_temp_dir() . '/sightpress-a11y-' . $copy;
// The crawl's site is written in three languages, one of them right to left; the journeys' site in two.
$locales = match ($copy) {
    'demo' => 'en-GB,pt-PT,he',
    'journeys', 'mediajourney' => 'en-GB,pt-PT',
    default => 'en-GB',
};
$port = is_numeric($_SERVER['SERVER_PORT'] ?? null) ? (int) $_SERVER['SERVER_PORT'] : 8087;
$mailbox = $project . '/mail';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
// Production, as a real site: pages may be indexed.
$environment = ['APP_ENV' => 'production', 'APP_SECRET' => SECRET];
// The M4 copies send text messages with the pretend "Test SMS" service.
$extended = in_array($copy, ['extended', 'extjourney'], true);
if ($extended) {
    $environment['SMS_API_KEY'] = 'test-sms-key-0123';
}

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
    ] + ($extended ? [
        'email_policy' => ['domains' => ['example.org']],
        'domain_registration' => 'on',
        // Every journey comes from 127.0.0.1, so the limit per device is raised; the others stay as they are.
        'sms' => ['provider' => 'test_sms', 'sender' => 'Riverside', 'limits' => ['per_client' => 100, 'per_site' => 200]],
    ] : []) + ($copy === 'mediajourney' ? [
        'media' => ['limits' => ['document' => '2G', 'max' => ['document' => '2G']]],
        'uploads' => ['temp_path' => $project . '/var/uploads', 'max_chunked_bytes' => '2G'],
    ] : []), true) . ';');
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 2, 'sites' => []], true) . ';');
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($project . '/config/sites.php', true);
    }
    if ($extended) {
        installPackages($project, $copy === 'extended');
    }
    // The `themed` copy has a second theme installed, as Composer would list it.
    if ($copy === 'themed') {
        @mkdir($project . '/vendor/composer', 0o777, true);
        file_put_contents($project . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]],
        ]]));
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
        '--locale' => $locales,
        '--name' => 'Riverside Library',
        '--url' => 'http://127.0.0.1:' . $port,
        '--admin-email' => 'ada@example.org',
        '--admin-first-name' => 'Ada',
        '--admin-last-name' => 'Lovelace',
        '--with-demo-content' => true,
    ]), $output = new BufferedOutput());
    if ($status !== 0) {
        throw new RuntimeException('site:create failed: ' . $output->fetch());
    }
    // The demo pages are published by site:create without the workflow: index them for site search (SR-1) now,
    // instead of waiting for schedule:run.
    if ($console->run(new ArrayInput(['command' => 'site:search:reindex', 'slug' => 'demo']), $output) !== 0) {
        throw new RuntimeException('site:search:reindex failed: ' . $output->fetch());
    }
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');
    $services = Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    $settings = Services::get($services, SiteSettings::class);
    // People may sign up on the crawl's and the journeys' sites (M4 ones too); the `members` copy is members-only.
    if (in_array($copy, ['demo', 'journeys', 'extended', 'extjourney'], true)) {
        $settings->set(SignUp::SETTING, '1');
    }
    if ($copy === 'members') {
        $settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
    }
    $users = Services::get($services, UserRepository::class);
    $hash = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(PASSWORD);
    foreach (['ada', 'bea', 'cleo', 'max'] as $name) {
        $user = $users->findByEmail($name . '@example.org') ?? throw new LogicException($name . ' is missing.');
        $user->setPasswordHash($hash, new DateTimeImmutable());
        $users->save($user);
    }
    $command = static function (array $input) use ($console): void {
        if ($console->run(new ArrayInput($input), $result = new BufferedOutput()) !== 0) {
            throw new RuntimeException('A command failed (' . json_encode($input) . '): ' . $result->fetch());
        }
    };
    if ($copy === 'themed') {
        $command(['command' => 'site:theme:apply', 'slug' => 'demo', 'theme' => 'acme-harbour']);
        $command(['command' => 'theme:publish-assets', '--no-screenshot' => true]);
    }
    // The `cloud` copy keeps its files in MinIO (the skeleton's service) and delivers them through a CDN address,
    // which the crawl answers in the browser.
    if ($copy === 'cloud') {
        moveToBucket($command, 'crawl');
        $command(['command' => 'site:storage:cdn', 'slug' => 'demo', '--url' => 'https://cdn.sightpress.test']);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($project . '/config/sites.php', true);
        }
    }
    if ($extended) {
        setUpExtended($kernel, $command, $project, $port, $copy === 'extended', $hash);
    }
}

/**
 * The rest of an M4 copy (see the top of this file): text messages on for the demo site, the members-only staff site
 * on `localhost` and, on `extended`, the events module with one event, the custom role and two-step confirmation.
 *
 * @param callable(array<string, mixed>): void $command
 */
function setUpExtended(Kernel $kernel, callable $command, string $project, int $port, bool $everything, string $hash): void
{
    $command(['command' => 'site:sms:enable', 'slug' => 'demo']);
    $command([
        'command' => 'site:create',
        'slug' => 'staff',
        '--domain' => 'localhost',
        '--db' => 'sqlite:///' . $project . '/staff.sqlite',
        '--owner-type' => 'organization',
        '--locale' => 'en-GB',
        '--name' => 'Riverside Staff',
        '--url' => 'http://localhost:' . $port,
        '--admin-email' => 'ada@example.org',
        '--admin-first-name' => 'Ada',
        '--admin-last-name' => 'Lovelace',
    ]);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($project . '/config/sites.php', true);
    }
    $sites = Services::get($kernel->container(), SiteRepository::class);
    $factory = Services::get($kernel->container(), SiteContainerFactory::class);
    $staff = $factory->forSite($sites->find('staff') ?? throw new LogicException('No staff site.'));
    Services::get($staff, SiteSettings::class)->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
    $users = Services::get($staff, UserRepository::class);
    $ada = $users->findByEmail('ada@example.org') ?? throw new LogicException('Ada is missing.');
    $ada->setPasswordHash($hash, new DateTimeImmutable());
    $users->save($ada);
    if (!$everything) {
        return;
    }

    $command(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => 'acme.events']);
    $command(['command' => 'site:role:create', 'slug' => 'demo', 'role' => 'events_team', '--label' => 'Events team', '--permissions' => 'events.manage,media.upload']);
    $demo = $sites->find('demo') ?? throw new LogicException('No site.');
    $factory->evict('demo');
    $services = $factory->forSite($demo);
    Services::get($services, Acme\Events\EventStore::class)->add('book-fair', 'Book fair', '2026-12-05T10:00:00+00:00');
    $users = Services::get($services, UserRepository::class);
    foreach (['ada' => '+447400123401', 'max' => '+447400123404'] as $name => $number) {
        $users->confirmPhone((int) ($users->findByEmail($name . '@example.org') ?? throw new LogicException($name . ' is missing.'))->id(), $number, 'mobile GB');
    }
    Services::get($services, SightPress\Core\Auth\StepUp\TwoStep::class)->choose(true, null);
}

// The published theme previews are static files the web server sends.
if (str_starts_with($path, '/themes/') && !str_contains($path, '..') && is_file($project . '/public' . $path)) {
    $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2'];
    header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($project . '/public' . $path);

    return;
}

// What an operator does on the server, for the themes and media journeys (journeys-themes-media.spec.ts).
if ($copy === 'mediajourney' && str_starts_with($path, '/_test/journey/')) {
    $console = Kernel::boot($project, $environment)->console();
    $console->setAutoExit(false);
    $output = new BufferedOutput();
    $status = 0;
    $command = static function (array $input) use ($console, $output, &$status): void {
        $status = $console->run(new ArrayInput($input), $output);
        if ($status !== 0) {
            throw new RuntimeException('A command failed (' . json_encode($input) . '): ' . $output->fetch());
        }
    };
    $folder = $project . '/import';
    switch (substr($path, strlen('/_test/journey/'))) {
        case 'install-theme':
        case 'remove-theme':
            @mkdir($project . '/vendor/composer', 0o777, true);
            file_put_contents($project . '/vendor/composer/installed.json', (string) json_encode(['packages' => $path === '/_test/journey/install-theme'
                ? [['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]]]
                : []]));
            break;
        case 'theme-list':
            $command(['command' => 'theme:list']);
            break;
        case 'import':
            @mkdir($folder . '/inner', 0o777, true);
            file_put_contents($folder . '/first.txt', "The first imported file.\n");
            file_put_contents($folder . '/inner/second.txt', "The second imported file.\n");
            file_put_contents($folder . '/inner/fake.pdf', "<!doctype html><script>alert(1)</script>\n");
            $status = $console->run(new ArrayInput(['command' => 'site:media:import', 'site' => 'demo', 'paths' => [$folder], '--recursive' => true, '--publish' => true]), $output);
            break;
        case 'bucket':
            moveToBucket($command, 'journey');
            break;
        case 'media':
            $kernel = Kernel::boot($project, $environment);
            $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');
            $library = Services::get(Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site), SightPress\Core\Media\MediaRepository::class);
            $found = array_values(array_filter($library->newest(100), static fn ($media): bool => $media->originalName() === ($_GET['name'] ?? '')));
            header('Content-Type: application/json');
            echo json_encode($found === [] ? null : ['id' => $found[0]->id(), 'checksum' => $found[0]->checksum()]);

            return;
        default:
            http_response_code(404);

            return;
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'output' => $output->fetch()]);

    return;
}

if ($extended && $path === '/_test/texts') {
    // The latest text message to a number (`?to=+447…`) and how many it got, as JSON, or 404.
    $texts = is_file($project . '/texts.json') ? json_decode((string) file_get_contents($project . '/texts.json'), true) : [];
    $to = array_values(array_filter(is_array($texts) ? $texts : [], static fn (mixed $text): bool => is_array($text) && ($text['to'] ?? null) === ($_GET['to'] ?? null)));
    foreach (array_reverse($to) as $text) {
        if (is_array($text)) {
            header('Content-Type: application/json');
            echo json_encode($text + ['count' => count($to)]);

            return;
        }
    }
    http_response_code(404);

    return;
}

// What an operator does on the server for the M4 journeys (journeys-m4.spec.ts): `composer require acme/events`,
// `composer remove acme/events`, and `site:user:phone:reset demo <email>` for someone who lost their phone.
if ($copy === 'extjourney' && in_array($path, ['/_test/journey/install-module', '/_test/journey/remove-module'], true)) {
    installPackages($project, $path === '/_test/journey/install-module');
    http_response_code(204);

    return;
}
if ($copy === 'extjourney' && $path === '/_test/journey/phone-reset') {
    $console = Kernel::boot($project, $environment)->console();
    $console->setAutoExit(false);
    $status = $console->run(new ArrayInput(['command' => 'site:user:phone:reset', 'slug' => 'demo', 'email' => is_string($_GET['email'] ?? null) ? $_GET['email'] : '']), $output = new BufferedOutput());
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'output' => $output->fetch()]);

    return;
}

// Runs `schedule:run` once (P-3), as the scheduler would, and answers with what it printed.
if ($path === '/_test/schedule') {
    $console = Kernel::boot($project, $environment)->console();
    $console->setAutoExit(false);
    $status = $console->run(new ArrayInput(['command' => 'schedule:run', '--no-interaction' => true]), $output = new BufferedOutput());
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code($status === 0 ? 200 : 500);
    echo $output->fetch();

    return;
}

if ($path === '/_test/mail') {
    $to = is_string($_GET['to'] ?? null) ? strtolower($_GET['to']) : '';
    $messages = glob($mailbox . '/*.json');
    $messages = $messages === false ? [] : $messages;
    rsort($messages);
    foreach ($messages as $file) {
        $message = json_decode((string) file_get_contents($file), true);
        if (is_array($message) && is_array($message['to'] ?? null) && in_array($to, $message['to'], true)) {
            header('Content-Type: application/json');
            echo json_encode($message);

            return;
        }
    }
    http_response_code(404);

    return;
}

$kernel = Kernel::boot($project, $environment);
$container = $kernel->container();
if (!$container instanceof DI\Container) {
    throw new LogicException('The kernel container can\'t be changed.');
}
$container->set(MailSender::class, new readonly class ($mailbox) implements MailSender {
    public function __construct(private string $folder)
    {
    }

    public function send(Email $email): void
    {
        @mkdir($this->folder, 0o777, true);
        file_put_contents($this->folder . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(4)) . '.json', json_encode([
            'to' => array_map(static fn ($address): string => strtolower($address->getAddress()), $email->getTo()),
            'subject' => $email->getSubject(),
            'text' => $email->getTextBody(),
            'html' => $email->getHtmlBody(),
        ]));
    }
});
$kernel->run();
