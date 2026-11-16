<?php

/**
 * A real site for the accessibility tests of the admin panel: the whole kernel with core's modules, one site on a
 * SQLite file (domain 127.0.0.1), one person per role and a few pages. Everyone's password is
 * "correct horse battery staple". Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * People: Ada (admin), Bea (editor), Cleo (contributor) and Max (member).
 * Test-only addresses: `/_test/reset` starts again from a fresh site (forgets sessions and rate limits);
 * `/_test/invitation?email=…&role=…&first_name=…` invites someone from Ada and answers with the link's path (the
 * test site sends no email).
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
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\People\InvitationMailer;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'correct horse battery staple';

$copy = getenv('SIGHTPRESS_A11Y_SITE');
$project = sys_get_temp_dir() . '/sightpress-a11y-' . (is_string($copy) && preg_match('/^[a-z]+$/', $copy) === 1 ? $copy : 'admin');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$environment = ['APP_ENV' => 'test', 'APP_SECRET' => SECRET];

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
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => ['demo' => [
        'id' => '7b1e4c2a-9d3f-4e8b-a6c5-1f2e3d4c5b6a',
        'name' => 'Riverside Library',
        'status' => 'enabled',
        'owner_type' => 'organization',
        'domains' => ['127.0.0.1'],
        'db_dsn' => new SecretBox(SECRET)->encrypt('sqlite:///' . $project . '/demo.sqlite'),
        'storage' => ['adapter' => 'local'],
        'default_locale' => 'en-GB',
        'locales' => ['en-GB'],
        'default_timezone' => 'Europe/London',
        'created_at' => '2026-11-16T09:00:00Z',
    ]]], true) . ';');

    $kernel = Kernel::boot($project, $environment);
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
}

if ($path === '/_test/invitation') {
    $kernel = Kernel::boot($project, $environment);
    $query = static fn (string $name): ?string => is_string($_GET[$name] ?? null) && $_GET[$name] !== '' ? $_GET[$name] : null;
    $ada = Services::get(site($kernel), UserRepository::class)->findByEmail('ada@example.org');
    [, $token] = Services::get(site($kernel), Invitations::class)->create(
        $query('email') ?? 'zoe@example.org',
        $query('role') ?? 'editor',
        $query('first_name'),
        null,
        $ada?->id(),
    );
    header('Content-Type: text/plain');
    echo InvitationMailer::PATH . $token;

    return;
}

Kernel::boot($project, $environment)->run();

function site(Kernel $kernel): Psr\Container\ContainerInterface
{
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');

    return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
}
