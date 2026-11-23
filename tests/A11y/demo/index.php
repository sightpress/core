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
 * crawl), so specs never reset each other's site. Sign-up is open on `demo` and `journeys`. Its search index is built after site:create.
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
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mime\Email;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'Correct horse battery staple 7!';

$copy = getenv('SIGHTPRESS_A11Y_SITE');
$copy = is_string($copy) && preg_match('/^[a-z]+$/', $copy) === 1 ? $copy : 'demo';
$project = sys_get_temp_dir() . '/sightpress-a11y-' . $copy;
// The crawl's site is written in three languages, one of them right to left; the journeys' site in two.
$locales = match ($copy) {
    'demo' => 'en-GB,pt-PT,he',
    'journeys' => 'en-GB,pt-PT',
    default => 'en-GB',
};
$port = is_numeric($_SERVER['SERVER_PORT'] ?? null) ? (int) $_SERVER['SERVER_PORT'] : 8087;
$mailbox = $project . '/mail';
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
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 2, 'sites' => []], true) . ';');
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
    // People may sign up on the crawl's and the journeys' sites; the `members` copy is a members-only site.
    if (in_array($copy, ['demo', 'journeys'], true)) {
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
