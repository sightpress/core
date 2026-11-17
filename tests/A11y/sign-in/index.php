<?php

/**
 * A real site for the accessibility tests of the sign-in pages: the whole kernel with core's modules, one site on a
 * SQLite file (domain 127.0.0.1) and two people, Ada (editor) and Max (member), whose password is
 * "correct horse battery staple", plus Nia (admin), who has no password yet and waits for a setup code. Started by
 * playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * Two test-only addresses: `/_test/reset` starts again from an empty site (forgets sessions and rate limits);
 * `/_test/code?purpose=…&email=…` answers with a fresh six-digit code for that purpose and address (it replaces the
 * one the site emailed, which goes nowhere here).
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\User\Contract\PendingAccounts;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';
const PASSWORD = 'correct horse battery staple';

$project = sys_get_temp_dir() . '/sightpress-a11y-sign-in';
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
        'id' => '3f0c2b9e-5d7a-4c1e-8b2f-0a6d9e4c7b1a',
        'name' => 'Riverside Library',
        'status' => 'enabled',
        'owner_type' => 'organization',
        'domains' => ['127.0.0.1'],
        'db_dsn' => new SecretBox(SECRET)->encrypt('sqlite:///' . $project . '/demo.sqlite'),
        'storage' => ['adapter' => 'local'],
        'default_locale' => 'en-GB',
        'locales' => ['en-GB'],
        'default_timezone' => 'Europe/London',
        'created_at' => '2026-11-15T09:00:00Z',
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
    foreach (['ada@example.org' => ['Ada', 'editor'], 'max@example.org' => ['Max', 'member']] as $email => [$name, $role]) {
        $user = new User($email, $name, 'Example', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher()->hash(PASSWORD), new DateTimeImmutable());
        Services::get($site, UserRepository::class)->save($user);
        $roles = Services::get($site, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new LogicException($role));
    }
    // Nia was made with `site:user:create-admin` and waits for her setup code.
    $nia = Services::get($site, PendingAccounts::class)->createPending('nia@example.org', 'Nia', 'Example', 'Europe/London');
    $roles = Services::get($site, RoleRepository::class);
    $roles->assign((int) $nia->id(), $roles->findRole('admin') ?? throw new LogicException('admin'));
}

$kernel = Kernel::boot($project, $environment);

if ($path === '/_test/code') {
    $site = site($kernel);
    $email = is_string($_GET['email'] ?? null) ? $_GET['email'] : 'ada@example.org';
    $purpose = VerificationPurpose::from(is_string($_GET['purpose'] ?? null) ? $_GET['purpose'] : 'password_reset');
    $account = Services::get($site, UserRepository::class)->findByEmail($email);
    header('Content-Type: text/plain');
    echo Services::get($site, VerificationCodes::class)->issue($purpose, VerificationChannel::Email, $email, $account?->id())->code;

    return;
}

$kernel->run();

function site(Kernel $kernel): Psr\Container\ContainerInterface
{
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');

    return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
}
