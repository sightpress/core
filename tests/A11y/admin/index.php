<?php

/**
 * A real site for the accessibility tests of the admin panel: the whole kernel with core's modules, one site on a
 * SQLite file (domain 127.0.0.1), one person per role and a few pages. Everyone's password is
 * "Correct horse battery staple 7!". Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * People: Ada (admin), Bea (editor), Cleo (contributor) and Max (member).
 * Test-only addresses: `/_test/reset` starts again from a fresh site (forgets sessions and rate limits);
 * `/_test/invitation?email=…&role=…&first_name=…` invites someone from Ada and answers with the invitation code (the
 * test site sends no email); `/_test/starter` writes the starter pages of a new site (I-4) in its languages;
 * `/_test/analytics` stores 60 days of analytics figures ending yesterday (AN-3).
 * Test mailbox: the site keeps every email it would send in its folder, and `/_test/mail?to=…` answers with the
 * latest one to that address as JSON (to, subject, text, html), or 404.
 *
 * The `features` copy (F-2) also has two optional modules of a test package: "Events" (on, with an event in its table)
 * and "acme.calendar" (off, needs Events).
 * The `assistant` copy (A-3) has the AI assistant on with the pretend "Test AI" service of a test package (nothing
 * leaves the server); `/_test/assistant` chooses it with a key and a model that sees pictures, so the assistant is
 * ready, and `/_test/picture` adds a picture to the media library and answers with its id. The `translate` copy
 * (A-6) is the same, written in British English and European Portuguese.
 *
 * The `appearance` copy (T-2) also has a second theme installed, "Harbour" (the contract's fake theme, through
 * vendor/composer/installed.json), and serves the previews `/_test/publish` (theme:publish-assets) writes to its public
 * folder (`/themes/…`), as a web server would.
 *
 * Each spec file gets its own copy on its own port (the environment variable SIGHTPRESS_A11Y_SITE names it), so
 * specs running side by side never reset each other's site.
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleAbout;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Modules\CalendarModule;
use SightPress\Core\Tests\Support\Modules\EventsModule;
use SightPress\Core\Tests\Support\Modules\TestAssistantModule;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'Correct horse battery staple 7!';

$copy = getenv('SIGHTPRESS_A11Y_SITE');
$project = sys_get_temp_dir() . '/sightpress-a11y-' . (is_string($copy) && preg_match('/^[a-z]+$/', $copy) === 1 ? $copy : 'admin');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$environment = ['APP_ENV' => 'test', 'APP_SECRET' => SECRET];
$mailbox = $project . '/mail';

if ($path === '/_test/reset') {
    exec('rm -rf ' . escapeshellarg($project));
    http_response_code(204);

    return;
}

if (basename($project) === 'sightpress-a11y-appearance' && !is_file($project . '/vendor/composer/installed.json')) {
    @mkdir($project . '/vendor/composer', 0o777, true);
    file_put_contents($project . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => SightPress\Core\Tests\Support\Theme\HarbourTheme::class]]],
    ]]));
}
// The published theme previews (`theme:publish-assets`) are static files the web server sends.
if (str_starts_with($path, '/themes/') && !str_contains($path, '..') && is_file($project . '/public' . $path)) {
    $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/vnd.microsoft.icon'];
    header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($project . '/public' . $path);

    return;
}

if (!is_file($project . '/config/sites.php')) {
    @mkdir($project . '/config', 0o777, true);
    file_put_contents($project . '/config/tenant.php', '<?php return ' . var_export([
        'log' => ['path' => $project . '/var/log/test.log'],
        'session' => ['path' => $project . '/var/sessions'],
    ], true) . ';');
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => ['demo' => [
        'id' => '7b1e4c2a-9d3f-4e8b-a6c5-1f2e3d4c5b6a',
        'name' => 'Riverside Library',
        'status' => 'enabled',
        'owner_type' => 'organization',
        'domains' => ['127.0.0.1'],
        'db_dsn' => new SecretBox(SECRET)->encrypt('sqlite:///' . $project . '/demo.sqlite'),
        'storage' => ['adapter' => 'local'],
        'default_locale' => 'en-GB',
        'locales' => basename($project) === 'sightpress-a11y-translate' ? ['en-GB', 'pt-PT'] : ['en-GB'],
        'default_timezone' => 'Europe/London',
        'created_at' => '2026-11-16T09:00:00Z',
    ]]], true) . ';');

    $kernel = boot($project, $environment);
    $site = site($kernel);
    $dependencies = DependencyFactory::fromConnection(
        new ExistingConfiguration(new MigrationConfigurationFactory()->create($kernel->modules())),
        new ExistingConnection(Services::get($site, Connection::class)),
    );
    $dependencies->getMetadataStorage()->ensureInitialized();
    $dependencies->getMigrator()->migrate(
        $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
        ),
        new MigratorConfiguration(),
    );
    Services::get($site, RbacSeeder::class)->seed();
    $roles = Services::get($site, RoleRepository::class);
    $hash = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(PASSWORD);
    $people = [];
    foreach ([
        'ada@example.org' => ['Ada', 'Lovelace', 'admin'],
        'bea@example.org' => ['Bea', 'Okafor', 'editor'],
        'cleo@example.org' => ['Cleo', 'Martins', 'contributor'],
        'max@example.org' => ['Max', 'Müller', 'member'],
    ] as $email => [$first, $last, $role]) {
        $user = new User($email, $first, $last, 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash($hash, new DateTimeImmutable());
        Services::get($site, UserRepository::class)->save($user);
        Services::get($site, AvatarService::class)->generate($user);
        Services::get($site, UserRepository::class)->save($user);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new LogicException($role));
        $people[$role] = (int) $user->id();
    }

    $contributor = $people['contributor'] ?? throw new LogicException('No contributor.');
    $editor = Services::get($site, PageEditor::class);
    $editor->create('en-GB', new PageContent('Opening hours'), authorId: $contributor);
    $summer = $editor->create('en-GB', new PageContent('Summer reading challenge'), authorId: $contributor);
    Services::get($site, PageWorkflow::class)->apply(
        $summer,
        PageTransition::Submit,
        new Actor($contributor, $roles->permissionNamesOf($contributor)),
    );
    $editor->create('en-GB', new PageContent('Volunteer with us'), authorId: $people['editor'] ?? null);
    if ($kernel->modules()->has(EventsModule::ID)) {
        Services::get($site, Connection::class)->insert('acme_event', ['title' => 'Book fair']);
    }
    if ($kernel->modules()->has(TestAssistantModule::ID)) {
        $demo = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');
        Services::get($kernel->container(), SiteModules::class)->turnOn($demo, 'assistant', null);
    }
}

if ($path === '/_test/assistant') {
    $kernel = boot($project, $environment);
    Services::get(site($kernel), AssistantSettings::class)->connect('test_ai', 'test-vision', null, 'sk-test-a11y-5f2b', null);
    http_response_code(204);

    return;
}

if ($path === '/_test/picture') {
    // A-4: a picture in the media library, for "Suggest alt text"; answers with its id.
    $kernel = boot($project, $environment);
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 160));
    $file = $project . '/reading-room.png';
    imagepng($image, $file);
    $media = Services::get(site($kernel), MediaUploader::class)->upload($file, 'reading-room.png', null)->media;
    header('Content-Type: text/plain');
    echo $media->id();

    return;
}

if ($path === '/_test/invitation') {
    $kernel = boot($project, $environment);
    $query = static fn (string $name): ?string => is_string($_GET[$name] ?? null) && $_GET[$name] !== '' ? $_GET[$name] : null;
    $ada = Services::get(site($kernel), UserRepository::class)->findByEmail('ada@example.org');
    [, $code] = Services::get(site($kernel), Invitations::class)->create(
        $query('email') ?? 'zoe@example.org',
        $query('role') ?? 'editor',
        $query('first_name'),
        null,
        $ada?->id(),
    );
    header('Content-Type: text/plain');
    echo $code->code;

    return;
}

// Publishes the installed themes' previews (theme:publish-assets, without screenshots) to the public folder.
if ($path === '/_test/publish') {
    $console = boot($project, $environment)->console();
    $console->setAutoExit(false);
    $status = $console->run(new Symfony\Component\Console\Input\ArrayInput(['command' => 'theme:publish-assets', '--no-screenshot' => true]), $output = new Symfony\Component\Console\Output\BufferedOutput());
    http_response_code($status === 0 ? 200 : 500);
    header('Content-Type: text/plain');
    echo $output->fetch();

    return;
}

if ($path === '/_test/starter') {
    $kernel = boot($project, $environment);
    Services::get(site($kernel), StarterContent::class)->create('Ada Lovelace', 'http://127.0.0.1', 'ada@example.org', null);
    http_response_code(204);

    return;
}

if ($path === '/_test/analytics') {
    // Analytics (AN-3): 60 finished days of figures, ending yesterday, so every report has something to show.
    $kernel = boot($project, $environment);
    $services = site($kernel);
    $store = Services::get($services, AnalyticsStore::class);
    $today = Services::get($services, AnalyticsRollup::class)->today(new DateTimeImmutable());
    for ($back = 60; $back >= 1; $back--) {
        $day = new DateTimeImmutable($today)->modify("-{$back} days")->format('Y-m-d');
        $visitors = 20 + (($back * 37) % 41);
        $figure = static fn (string $metric, string $dimension, string $item, int $value): array => ['metric' => $metric, 'dimension' => $dimension, 'item' => $item, 'value' => $value];
        $store->finishDay($day, [
            $figure('visitors', '', '', $visitors),
            $figure('views', '', '', $visitors * 2),
            $figure('views', 'page', '1:en-GB', $visitors),
            $figure('visitors', 'page', '1:en-GB', $visitors),
            $figure('views', 'page', '2:en-GB', $visitors),
            $figure('visitors', 'page', '2:en-GB', (int) ($visitors / 2)),
            $figure('visitors', 'source', 'direct', $visitors - 5),
            $figure('visitors', 'source', 'search:Google', 4),
            $figure('visitors', 'source', 'ai:ChatGPT', 1),
            $figure('views', 'source', 'direct', $visitors),
            $figure('visitors', 'device', 'computer', $visitors - 8),
            $figure('visitors', 'device', 'phone', 8),
            $figure('views', 'device', 'computer', $visitors),
            $figure('not_found', 'path', '/old-news', 2),
            $figure('bot_reads', 'bot', 'Googlebot|search|page', 12),
            $figure('bot_reads', 'bot', 'GPTBot|ai_training|llms', 1),
            $figure('searches', '', '', 3),
            $figure('searches', 'query', 'opening hours', 2),
            $figure('searches', 'query', 'parking', 1),
            $figure('empty_searches', '', '', 1),
            $figure('empty_searches', 'query', 'parking', 1),
        ]);
    }
    http_response_code(204);

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

$kernel = boot($project, $environment);
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

/**
 * @param array<string, string> $environment
 */
function boot(string $project, array $environment): Kernel
{
    if (in_array(basename($project), ['sightpress-a11y-assistant', 'sightpress-a11y-translate'], true)) {
        return Kernel::fromConfig(new TenantConfigLoader()->load($project, $environment), [
            ...CoreModules::candidates(),
            new ModuleCandidate(new TestAssistantModule(), 'acme/test-ai'),
        ]);
    }
    if (basename($project) !== 'sightpress-a11y-features') {
        return Kernel::boot($project, $environment);
    }
    $about = [EventsModule::ID => ModuleAbout::fromArray(EventsModule::ABOUT, EventsModule::ID)];

    return Kernel::fromConfig(new TenantConfigLoader()->load($project, $environment), [
        ...CoreModules::candidates(),
        new ModuleCandidate(new EventsModule(), 'acme/events', '*', $about),
        new ModuleCandidate(new CalendarModule(), 'acme/events', '*', $about),
    ]);
}

function site(Kernel $kernel): Psr\Container\ContainerInterface
{
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');

    return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
}
