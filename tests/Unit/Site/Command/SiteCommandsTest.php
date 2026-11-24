<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Command\ArchiveSiteCommand;
use SightPress\Core\Site\Command\ChangeSiteDatabaseCommand;
use SightPress\Core\Site\Command\CreateSiteCommand;
use SightPress\Core\Site\Command\DeleteSiteCommand;
use SightPress\Core\Site\Command\DisableSiteCommand;
use SightPress\Core\Site\Command\EnableSiteCommand;
use SightPress\Core\Site\Command\ListSitesCommand;
use SightPress\Core\Site\Command\MigrateSitesCommand;
use SightPress\Core\Site\Command\PurgeSiteCommand;
use SightPress\Core\Site\Command\SiteStatusCommand;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\UserRepository;
use SightPress\Core\User\UserStatus;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(CreateSiteCommand::class)]
#[CoversClass(ListSitesCommand::class)]
#[CoversClass(SiteStatusCommand::class)]
#[CoversClass(EnableSiteCommand::class)]
#[CoversClass(DisableSiteCommand::class)]
#[CoversClass(ArchiveSiteCommand::class)]
#[CoversClass(DeleteSiteCommand::class)]
#[CoversClass(PurgeSiteCommand::class)]
#[CoversClass(MigrateSitesCommand::class)]
#[CoversClass(ChangeSiteDatabaseCommand::class)]
#[CoversClass(SiteMigrator::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteCommandsTest extends TestCase
{
    private TestProject $project;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->project = new TestProject(['timezone' => 'Europe/Lisbon']);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCreatesASiteWithItsFirstAdminAndRoles(): void
    {
        $status = $this->create('demo', ['--domain' => 'Demo.Example, www.demo.example', '--name' => 'Riverside Library']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        $lines = explode("\n", trim($this->stdout));
        self::assertSame('Getting the SQLite file ' . $this->database('demo') . ' ready…', $lines[0]);
        self::assertMatchesRegularExpression('/^Ran \d+ database migrations\.$/', $lines[1]);
        self::assertSame('Created the account of the first admin, Ada Lovelace (ada@example.org).', $lines[2]);
        self::assertSame('Set up the roles admin, editor, contributor and member.', $lines[3]);
        self::assertSame('Gave the first admin the admin role.', $lines[4]);
        self::assertSame(1, preg_match('#^Give the setup code (\d{3}) (\d{3}) to ada@example\.org\. They go to https://demo\.example/login/setup, type their email address and the code, and choose their password\. The code works once, for 7 days\.$#', $lines[5], $code));
        self::assertSame('Made the site\'s icons, wordmark and share image from its name; replace them with site:branding:set.', $lines[6]);
        self::assertSame('Published the starter pages in British English: Welcome to Riverside Library, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.', $lines[7]);
        self::assertSame('Added 5 starter pages to the main and footer menus.', $lines[8]);
        self::assertSame('The site "demo" is ready at https://demo.example.', $lines[9]);
        self::assertStringNotContainsString('sqlite:', $this->stdout, 'the DSN itself is never printed');

        $site = $this->site('demo');
        self::assertSame('Riverside Library', $site->name);
        self::assertSame(SiteStatus::Enabled, $site->status);
        self::assertSame(['demo.example', 'www.demo.example'], $site->domains);
        self::assertSame('Europe/Lisbon', $site->defaultTimezone, 'the tenant\'s time zone by default');
        self::assertSame(['en-GB'], $site->locales);
        self::assertMatchesRegularExpression(Site::ID_PATTERN, $site->id);

        $services = $this->services($site);
        $admin = Services::get($services, UserRepository::class)->findByEmail('ada@example.org');
        self::assertNotNull($admin);
        self::assertSame(UserStatus::Pending, $admin->status());
        self::assertSame('Europe/Lisbon', $admin->timezone());
        self::assertSame(['admin'], array_map(static fn ($r) => $r->name(), Services::get($services, RoleRepository::class)->rolesOf((int) $admin->id())));
        $check = Services::get($services, VerificationCodes::class)->check(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'ada@example.org', $code[1] . $code[2], '192.0.2.1');
        self::assertSame($admin->id(), $check->accountId);
        self::assertSame(0, Services::get($this->kernel()->container(), SiteMigrator::class)->pending($site));
        self::assertStringContainsString('sites.NOTICE: Site "demo" created (enabled).', $this->project->log());
    }

    public function testTheTimeZoneAndTheAddressOfTheLinksCanBeChosen(): void
    {
        $status = $this->create('demo', ['--timezone' => 'America/Sao_Paulo', '--url' => 'http://localhost:8080/', '--owner-type' => 'Person']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        self::assertStringContainsString('They go to http://localhost:8080/login/setup,', $this->stdout);
        self::assertStringContainsString('The site "demo" is ready at http://localhost:8080.', $this->stdout);
        self::assertSame('America/Sao_Paulo', $this->site('demo')->defaultTimezone);
        self::assertSame('demo', $this->site('demo')->name, 'the name defaults to the slug');
    }

    public function testTheDatabaseCanBeGivenInPiecesAndTheLocaleAndTimeZoneInAnyCase(): void
    {
        $status = $this->create('demo', ['--db' => '', '--db-path' => 'pieces/demo.sqlite', '--locale' => 'PT_br', '--timezone' => 'america/sao_paulo', '--domain' => 'https://Demo.Example/home']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        self::assertFileExists($this->project->path() . '/sites/pieces/demo.sqlite');
        $site = $this->site('demo');
        self::assertSame('pt-BR', $site->defaultLocale);
        self::assertSame('America/Sao_Paulo', $site->defaultTimezone);
        self::assertSame(['demo.example'], $site->domains);
        self::assertStringContainsString('The site "demo" is ready at https://demo.example.', $this->stdout);
    }

    public function testADatabaseServerIsAskedForInPiecesWithItsCredentialsEncoded(): void
    {
        $status = $this->create('demo', [
            '--db' => '',
            '--db-engine' => 'PostgreSQL',
            '--db-host' => '127.0.0.1',
            '--db-port' => '1',
            '--db-user' => 'ada lovelace',
            '--db-password' => 'p@ss:/word',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('PostgreSQL database "sightpress_demo" on 127.0.0.1:1', $this->stderr);
        self::assertStringNotContainsString('p@ss', $this->stdout . $this->stderr);
    }

    public function testAsksForEverythingAtTheTerminalAndChecksTheAnswersBeforeCreatingTheSite(): void
    {
        $status = $this->console(['command' => 'site:create'], inputs: [
            'Demo',               // short name
            'Riverside Library',  // name
            'https://Demo.Example/',
            '',                   // address of the links: https://demo.example
            'Person',
            'pt_br', 'no',        // Brazilian Portuguese? no
            'en_gb', '',          // British English? yes
            '',                   // time zone: the tenant's
            '',                   // engine: sqlite
            '',                   // file: the sites folder
            'ada@', 'ada at example.org', 'Ada@Example.org',
            'Ada', 'Lovelace',
            '',                   // no demo content
            'no',                 // start again
            '', 'Riverside Library and Archive', '', '', '', '', '', '', '', '', '', '', '', '', '',
            'yes',
        ]);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        self::assertStringContainsString("That is Brazilian Portuguese (pt-BR). Is that right?", $this->stderr);
        self::assertStringContainsString("That is British English (en-GB). Is that right?", $this->stderr);
        self::assertStringContainsString("Which database engine does the site use? SQLite keeps everything in one file and needs no database server. Type sqlite, mysql, mariadb or postgresql. Press Enter for sqlite.\n", $this->stderr);
        self::assertStringContainsString("\"ada@\" is not an email address: type one such as ada@example.org.\nEmail address of the first admin:\n> \"ada at example.org\" is not an email address", $this->stderr);
        self::assertStringNotContainsString('Create the database', $this->stderr, 'SQLite needs no server to create it on');
        self::assertStringContainsString(
            "Here are your answers:\nShort name: demo\nName: Riverside Library and Archive\nDomains: demo.example\nAddress of the links: https://demo.example\n"
            . "Run by: person\nLanguages: British English (en-GB)\nTime zone: Europe/Lisbon\nDatabase engine: sqlite\nSQLite file: " . $this->database('demo') . "\n"
            . "First admin's email: ada@example.org\nFirst admin's first name: Ada\nFirst admin's last name: Lovelace\nDemo content: no\n",
            $this->stdout,
        );
        $site = $this->site('demo');
        self::assertSame('Riverside Library and Archive', $site->name);
        self::assertSame('en-GB', $site->defaultLocale);
        self::assertSame('person', $site->ownerType->value);
    }

    public function testStopsAfterThreeWrongAnswersWithoutCreatingAnything(): void
    {
        $status = $this->console(['command' => 'site:create', 'slug' => 'demo', '--domain' => 'demo.example', '--owner-type' => 'person', '--locale' => 'cy', '--db' => 'sqlite:///' . $this->database('demo')], inputs: [
            '', '', '', // name, address, time zone
            'no', 'ada@', 'ada', '@',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('That answer was not accepted 3 times, so the command stopped without changing anything.', $this->stderr);
        self::assertNull($this->sites()->find('demo'));
        self::assertFileDoesNotExist($this->database('demo'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'slug' => [['slug' => 'Not A Slug'], '"not a slug" is not a short name: use lower-case letters, digits and hyphens, for example demo.'];
        yield 'no domain' => [['--domain' => ' , '], 'Give the site at least one domain, for example example.org.'];
        yield 'domain' => [['--domain' => 'exa_mple.org'], '"exa_mple.org" is not a domain: use one such as example.org or localhost.'];
        yield 'owner type' => [['--owner-type' => 'company'], '"company" is not one of the choices: type person or organization.'];
        yield 'locale' => [['--locale' => 'english'], '"english" is not a locale: use one such as en-GB or pt-BR.'];
        yield 'unknown locale' => [['--locale' => 'xx_YY'], '"xx_YY" is not a locale: use one such as en-GB or pt-BR.'];
        yield 'time zone' => [['--timezone' => 'Mars/Olympus'], '"Mars/Olympus" is not a time zone: use one such as Europe/London.'];
        yield 'admin email' => [['--admin-email' => 'ada@'], '"ada@" is not an email address: type one such as ada@example.org.'];
        yield 'database engine' => [['--db' => '', '--db-engine' => 'oracle'], '"oracle" is not one of the choices: type sqlite, mysql, mariadb or postgresql.'];
        yield 'database file' => [['--db' => '', '--db-path' => 'demo/'], 'Give the path of a file, not of a folder, for example /srv/sites/demo.sqlite.'];
        yield 'database server' => [['--db' => '', '--db-engine' => 'postgresql', '--db-host' => 'db server'], '"db server" is not a server name or address: use one such as localhost, db or 10.0.0.5.'];
        yield 'database port' => [['--db' => '', '--db-engine' => 'mysql', '--db-port' => '70000'], '"70000" is not a port: use a whole number from 1 to 65535, such as 5432.'];
        yield 'database name' => [['--db' => '', '--db-engine' => 'mariadb', '--db-name' => 'demo;drop'], '"demo;drop" is not a database name: use letters, digits and _, such as sightpress_demo.'];
        yield 'dsn' => [['--db' => 'oracle://db/demo'], 'The database DSN must start with sqlite://, mysql://, mariadb:// or postgresql://.'];
        yield 'url' => [['--url' => 'localhost:8080'], '"localhost:8080" is not a web address: use one such as https://example.org.'];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptions')]
    public function testWrongOptionsAreExplainedBeforeAnythingIsCreated(array $options, string $message): void
    {
        self::assertSame(Command::INVALID, $this->create('demo', $options));
        self::assertStringContainsString($message, $this->stderr);
        self::assertSame('', $this->stdout);
        self::assertFileDoesNotExist($this->database('demo'));
    }

    public function testTheTenantsPoliciesDecideTheDomainsAndTheFirstAdmin(): void
    {
        $this->project->remove();
        $this->project = new TestProject(['domain_policy' => ['subdomain_of' => 'example.org'], 'email_policy' => ['domains' => ['example.org']]]);

        self::assertSame(Command::INVALID, $this->create('demo'));
        self::assertSame("demo.example can’t be used: sites here must use example.org or a name under it, such as www.example.org.\n", $this->stderr);
        self::assertSame(Command::INVALID, $this->create('demo', ['--domain' => 'news.example.org, evil-example.org']));
        self::assertStringStartsWith('evil-example.org can’t be used', $this->stderr);
        self::assertSame(Command::INVALID, $this->create('demo', ['--domain' => 'news.example.org', '--admin-email' => 'ada@mail.example.org']));
        self::assertSame("Only email addresses at example.org can join this site. Use an address there.\n", $this->stderr);

        self::assertSame(Command::SUCCESS, $this->create('demo', ['--domain' => 'News.Example.ORG., bücher.example.org']), $this->stderr);
        self::assertSame(['news.example.org', 'xn--bcher-kva.example.org'], $this->sites()->find('demo')?->domains);
    }

    public function testASlugOrADomainCanOnlyBeUsedOnce(): void
    {
        $this->create('demo');

        self::assertSame(Command::INVALID, $this->create('demo', ['--domain' => 'other.example']));
        self::assertSame("There is already a site \"demo\". Choose another short name.\n", $this->stderr);
        self::assertSame(Command::INVALID, $this->create('other', ['--domain' => 'other.example, DEMO.example.']));
        self::assertSame("The domain demo.example already belongs to the site \"demo\".\n", $this->stderr);
    }

    public function testASiteNeedsTheSecretThatEncryptsItsDatabaseAddress(): void
    {
        self::assertSame(Command::INVALID, $this->create('demo', [], []));
        self::assertSame("APP_SECRET is not set: set it in the environment before you create a site.\n", $this->stderr);
    }

    public function testADatabaseThatIsNotReadyStopsTheCommand(): void
    {
        self::assertSame(Command::FAILURE, $this->create('demo', ['--db' => 'sqlite:////proc/sightpress/demo.sqlite']));
        self::assertSame("The folder of the SQLite file /proc/sightpress/demo.sqlite can't be created.\n", $this->stderr);
        self::assertNull($this->sites()->find('demo'));
    }

    public function testAFailingSetUpStepKeepsTheSiteOutOfTheRegistry(): void
    {
        self::assertSame(Command::FAILURE, $this->create('demo', ['--admin-first-name' => str_repeat('A', 101)]));

        self::assertStringStartsWith('Setting up the site failed: ', $this->stderr);
        self::assertStringContainsString('The site was not added to the registry.', $this->stderr);
        self::assertNull($this->sites()->find('demo'));
    }

    public function testListsTheSitesInSentences(): void
    {
        $this->console(['command' => 'site:list']);
        self::assertSame("There are no sites yet. Create one with site:create.\n", $this->stdout);

        $this->create('demo');
        $this->create('other', ['--domain' => 'other.example']);
        $this->console(['command' => 'site:list']);

        self::assertSame(
            "2 sites:\n"
            . 'demo ("demo"): enabled. Domains: demo.example. Language: en-GB. Time zone: Europe/Lisbon. Database: SQLite file ' . $this->database('demo') . ".\n"
            . 'other ("other"): enabled. Domains: other.example. Language: en-GB. Time zone: Europe/Lisbon. Database: SQLite file ' . $this->database('other') . ".\n",
            $this->stdout,
        );

        $this->console(['command' => 'site:list'], []);
        self::assertStringContainsString('Database: not shown, APP_SECRET is not set.', $this->stdout);
        $this->console(['command' => 'site:list'], ['APP_SECRET' => 'another-secret-0123456789abcdef0123456789']);
        self::assertStringContainsString('Database: can\'t be read with this APP_SECRET.', $this->stdout);
    }

    public function testChangesTheStatusOfASite(): void
    {
        $this->create('demo', ['--domain' => 'demo.example, www.demo.example']);

        foreach ([
            ['site:disable', 'The site "demo" is disabled: visitors get a "temporarily unavailable" page (503) until you run site:enable demo.', SiteStatus::Disabled],
            ['site:archive', 'The site "demo" is archived: visitors are told the site is gone (410). Its data is kept.', SiteStatus::Archived],
            ['site:delete', 'The site "demo" is deleted: it no longer answers (404) and its data is kept. Run site:enable demo to bring it back, or site:purge demo to remove it for good.', SiteStatus::Deleted],
            ['site:enable', 'The site "demo" is enabled: it answers at demo.example, www.demo.example.', SiteStatus::Enabled],
        ] as [$command, $message, $status]) {
            self::assertSame(Command::SUCCESS, $this->console(['command' => $command, 'slug' => 'demo']));
            self::assertSame($message . "\n", $this->stdout);
            self::assertSame($status, $this->site('demo')->status);
        }

        self::assertStringContainsString('sites.NOTICE: Site "demo" status changed from archived to deleted.', $this->project->log());
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:enable', 'slug' => 'demo']));
        self::assertSame("The site \"demo\" is already enabled.\n", $this->stdout);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:disable', 'slug' => 'ghost']));
        self::assertSame("There is no site \"ghost\". See the sites with site:list.\n", $this->stderr);
    }

    public function testEveryStatusCommandDescribesItself(): void
    {
        $application = $this->kernel()->console();

        self::assertStringStartsWith('Turns a site on', $application->find('site:enable')->getDescription());
        self::assertStringStartsWith('Turns a site off for a while', $application->find('site:disable')->getDescription());
        self::assertStringStartsWith('Archives a site', $application->find('site:archive')->getDescription());
        self::assertStringStartsWith('Deletes a site, softly', $application->find('site:delete')->getDescription());
        self::assertStringStartsWith('Removes a deleted site and its files for good', $application->find('site:purge')->getDescription());
        self::assertStringStartsWith('Lists the sites', $application->find('site:list')->getDescription());
        self::assertStringStartsWith('Runs the database migrations', $application->find('site:migrate')->getDescription());
        self::assertStringStartsWith('Creates a site', $application->find('site:create')->getDescription());
        self::assertStringStartsWith('Points a disabled site at another database', $application->find('site:db:change')->getDescription());
    }

    public function testOnlyADeletedSiteCanBePurgedAndItsDatabaseIsKeptUnlessAsked(): void
    {
        $this->create('demo');
        $this->create('other', ['--domain' => 'other.example']);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:purge', 'slug' => 'demo']));
        self::assertSame("The site \"demo\" is enabled. Only deleted sites can be purged: run site:delete demo first.\n", $this->stderr);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:purge', 'slug' => 'ghost']));

        $this->console(['command' => 'site:delete', 'slug' => 'demo']);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:purge', 'slug' => 'demo']));
        self::assertSame(
            "The site \"demo\" and its files were removed.\nIts SQLite file " . $this->database('demo') . " was kept: delete it yourself when you no longer need it.\n",
            $this->stdout,
        );
        self::assertNull($this->sites()->find('demo'));
        self::assertFileExists($this->database('demo'));

        $this->console(['command' => 'site:delete', 'slug' => 'other']);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:purge', 'slug' => 'other', '--drop-database' => true]));
        self::assertSame("The site \"other\" and its files were removed.\nDeleted its SQLite file " . $this->database('other') . ".\n", $this->stdout);
        self::assertFileDoesNotExist($this->database('other'));
        self::assertStringContainsString('sites.NOTICE: Site "other" purged.', $this->project->log());
    }

    public function testADatabaseThatCantBeReadOrDeletedIsNeverLeftWithoutItsSite(): void
    {
        $other = new SecretBox('another-secret-0123456789abcdef0123456789');
        $this->registerDeleted('locked', $other->encrypt('sqlite:///' . $this->database('locked')));
        $this->registerDeleted('remote', SiteFixture::secretBox()->encrypt('postgresql://u:p@127.0.0.1:1/remote'));

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:purge', 'slug' => 'locked', '--drop-database' => true]));
        self::assertSame("The database address can't be read with this APP_SECRET, so the database can't be deleted. The site was not purged.\n", $this->stderr);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:purge', 'slug' => 'remote', '--drop-database' => true]));
        self::assertStringEndsWith(" The site was not purged.\n", $this->stderr);
        self::assertNotNull($this->sites()->find('remote'));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:purge', 'slug' => 'locked']));
        self::assertStringEndsWith("Its database was kept: delete it yourself when you no longer need it.\n", $this->stdout);
    }

    public function testMigratesOneSiteOrAllOfThem(): void
    {
        self::assertSame(Command::INVALID, $this->console(['command' => 'site:migrate']));
        self::assertSame("Give the slug of one site, or --all, but not both.\n", $this->stderr);
        self::assertSame(Command::INVALID, $this->console(['command' => 'site:migrate', 'slug' => 'demo', '--all' => true]));
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:migrate', '--all' => true]));
        self::assertSame("There are no sites to migrate.\n", $this->stdout);

        $this->create('demo');
        $this->registerDeleted('gone', SiteFixture::secretBox()->encrypt('sqlite:///' . $this->database('gone')));
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:migrate', 'slug' => 'demo']));
        self::assertSame("demo: already up to date.\n", $this->stdout);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:migrate', 'slug' => 'ghost']));

        $this->registerDeleted('fresh', SiteFixture::secretBox()->encrypt('sqlite:///' . $this->database('fresh')), SiteStatus::Disabled);
        touch($this->database('fresh'));
        $this->registerDeleted('broken', new SecretBox('another-secret-0123456789abcdef0123456789')->encrypt('sqlite:///x'), SiteStatus::Enabled);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:migrate', '--all' => true]));
        self::assertMatchesRegularExpression('/^demo: already up to date\.\nfresh: ran \d+ migrations\.\n$/', $this->stdout);
        self::assertStringStartsWith('broken: the migrations failed: ', $this->stderr);
        self::assertStringNotContainsString('gone', $this->stdout . $this->stderr, 'deleted sites are left alone');
    }

    public function testSaysWhenOneMigrationRan(): void
    {
        $this->create('demo');
        $connection = Services::get($this->services($this->site('demo')), \Doctrine\DBAL\Connection::class);
        $connection->delete('migration_versions', ['version' => 'SightPress\Core\Auth\Migrations\Version20261114130000']);
        $connection->createSchemaManager()->dropTable('auth_remember_tokens');

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:migrate', 'slug' => 'demo']));
        self::assertSame("demo: ran 1 migration.\n", $this->stdout);
    }

    public function testOnlyADisabledSiteCanChangeItsDatabase(): void
    {
        $this->create('demo');
        $before = $this->site('demo')->databaseDsn;

        foreach (['enabled' => null, 'archived' => 'site:archive', 'deleted' => 'site:delete'] as $status => $command) {
            if ($command !== null) {
                $this->console(['command' => $command, 'slug' => 'demo']);
            }
            self::assertSame(Command::FAILURE, $this->changeDatabase('demo', 'moved', ['--migrate' => true]));
            self::assertSame(
                "The site \"demo\" is {$status}. Only a disabled site can change its database: run site:disable demo first.\n",
                $this->stderr,
            );
        }
        self::assertSame($before, $this->site('demo')->databaseDsn);
        self::assertFileDoesNotExist($this->database('moved'));
        self::assertSame(Command::FAILURE, $this->changeDatabase('ghost', 'moved'));
        self::assertSame("There is no site \"ghost\". See the sites with site:list.\n", $this->stderr);
    }

    public function testADatabaseThatIsBehindNeedsMigrate(): void
    {
        $this->create('demo');
        $this->console(['command' => 'site:disable', 'slug' => 'demo']);
        $before = $this->site('demo')->databaseDsn;

        self::assertSame(Command::FAILURE, $this->changeDatabase('demo', 'moved'));
        self::assertMatchesRegularExpression(
            '/^The SQLite file ' . preg_quote($this->database('moved'), '/') . ' is \d+ database migrations behind\. Run the command again with --migrate to run them, or use an up-to-date database\. The site still uses the SQLite file ' . preg_quote($this->database('demo'), '/') . "\\.\n$/",
            $this->stderr,
        );
        self::assertSame($before, $this->site('demo')->databaseDsn, 'the registry is unchanged');

        self::assertSame(Command::SUCCESS, $this->changeDatabase('demo', 'moved', ['--migrate' => true]), $this->stderr);
        $lines = explode("\n", trim($this->stdout));
        self::assertMatchesRegularExpression('/^Ran \d+ database migrations\.$/', $lines[0]);
        self::assertSame(
            'The site "demo" now uses the SQLite file ' . $this->database('moved') . '. Nothing was copied from the SQLite file ' . $this->database('demo') . '. Run site:enable demo when you are ready.',
            $lines[1],
        );

        $site = $this->site('demo');
        self::assertSame(SiteStatus::Disabled, $site->status);
        self::assertTrue(SecretBox::looksEncrypted($site->databaseDsn));
        self::assertStringNotContainsString('moved', $site->databaseDsn, 'stored encrypted');
        self::assertSame('sqlite:///' . $this->database('moved'), SiteFixture::secretBox()->decrypt($site->databaseDsn));
        self::assertSame(0, Services::get($this->kernel()->container(), SiteMigrator::class)->pending($site));
        $log = $this->project->log();
        self::assertStringContainsString('sites.NOTICE: Site "demo" database changed from SQLite file ' . $this->database('demo') . ' to SQLite file ' . $this->database('moved') . '.', $log);
        self::assertStringNotContainsString('sqlite:', $log, 'the log never has the DSN itself');

        self::assertSame(Command::SUCCESS, $this->changeDatabase('demo', 'moved'));
        self::assertSame('The site "demo" already uses the SQLite file ' . $this->database('moved') . ".\n", $this->stdout);
    }

    public function testAnUpToDateCopyIsUsedAsItIs(): void
    {
        $this->create('demo');
        $this->console(['command' => 'site:disable', 'slug' => 'demo']);
        copy($this->database('demo'), $this->database('copy'));

        self::assertSame(Command::SUCCESS, $this->changeDatabase('demo', 'copy'), $this->stderr);
        self::assertStringStartsWith('The site "demo" now uses the SQLite file ' . $this->database('copy') . '.', $this->stdout);
        self::assertNotNull(Services::get($this->services($this->site('demo')), UserRepository::class)->findByEmail('ada@example.org'));
    }

    public function testADatabaseFromANewerVersionIsRefused(): void
    {
        $this->create('demo');
        $this->console(['command' => 'site:disable', 'slug' => 'demo']);
        Services::get($this->services($this->site('demo')), \Doctrine\DBAL\Connection::class)
            ->insert('migration_versions', ['version' => 'Acme\Migrations\Version20990101000000', 'executed_at' => '2099-01-01 00:00:00', 'execution_time' => 1]);
        copy($this->database('demo'), $this->database('newer'));

        self::assertSame(Command::FAILURE, $this->changeDatabase('demo', 'newer', ['--migrate' => true]));
        self::assertSame(
            'The SQLite file ' . $this->database('newer') . ' has 1 database migration that no installed module has: it belongs to a newer version of SightPress or to a module that isn\'t installed. The site still uses the SQLite file ' . $this->database('demo') . ".\n",
            $this->stderr,
        );
    }

    public function testADatabaseThatCantBeCheckedOrReachedIsRefused(): void
    {
        $this->registerDeleted('demo', new SecretBox('another-secret-0123456789abcdef0123456789')->encrypt('sqlite:///x'), SiteStatus::Disabled);
        mkdir(dirname($this->database('broken')));
        file_put_contents($this->database('broken'), str_repeat('not a database ', 100));

        self::assertSame(Command::FAILURE, $this->changeDatabase('demo', 'broken'));
        self::assertStringStartsWith('The migrations of the SQLite file ' . $this->database('broken') . ' could not be checked or run: ', $this->stderr);
        self::assertStringEndsWith(" The site still uses the old database.\n", $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => 'sqlite:////proc/sightpress/demo.sqlite']));
        self::assertSame("The folder of the SQLite file /proc/sightpress/demo.sqlite can't be created. The site still uses the old database.\n", $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => 'postgresql://ada:secretpw@127.0.0.1:1/demo']));
        self::assertStringNotContainsString('secretpw', $this->stderr);
        self::assertStringContainsString('PostgreSQL database "demo" on 127.0.0.1:1', $this->stderr);
    }

    public function testAWrongAddressOrAMissingSecretIsExplained(): void
    {
        $this->registerDeleted('demo', SiteFixture::secretBox()->encrypt('sqlite:///' . $this->database('demo')), SiteStatus::Disabled);

        self::assertSame(Command::INVALID, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => 'oracle://db/demo']));
        self::assertSame("The database DSN must start with sqlite://, mysql://, mariadb:// or postgresql://.\n", $this->stderr);
        self::assertSame(Command::INVALID, $this->console(['command' => 'site:db:change', 'slug' => 'demo', '--db' => 'sqlite:///' . $this->database('x')], []));
        self::assertSame("APP_SECRET is not set: set it in the environment before you change a site's database.\n", $this->stderr);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function changeDatabase(string $slug, string $database, array $options = []): int
    {
        return $this->console(['command' => 'site:db:change', 'slug' => $slug, '--db' => 'sqlite:///' . $this->database($database), ...$options]);
    }

    /**
     * @param array<string, string> $options
     * @param array<string, string>|null $environment
     */
    private function create(string $slug, array $options = [], ?array $environment = null): int
    {
        return $this->console([
            'command' => 'site:create',
            'slug' => $slug,
            '--domain' => $slug . '.example',
            '--db' => 'sqlite:///' . $this->database($slug),
            '--owner-type' => 'organization',
            '--locale' => 'en-GB',
            '--admin-email' => 'Ada@Example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
            ...$options,
        ], $environment);
    }

    /**
     * @param array<string, mixed>       $input
     * @param array<string, string>|null $environment
     * @param list<string>|null          $inputs      answers typed at the terminal; null for no terminal
     */
    private function console(array $input, ?array $environment = null, ?array $inputs = null): int
    {
        $application = $this->kernel($environment)->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        if ($inputs !== null) {
            $tester->setInputs($inputs);
        }
        $status = $tester->run($input, ['interactive' => $inputs !== null, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }

    /**
     * @param array<string, string>|null $environment
     */
    private function kernel(?array $environment = null): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', ...($environment ?? ['APP_SECRET' => SiteFixture::SECRET])]);
    }

    private function database(string $slug): string
    {
        return $this->project->path() . '/sites/' . $slug . '.sqlite';
    }

    private function sites(): SiteRepository
    {
        return Services::get($this->kernel()->container(), SiteRepository::class);
    }

    private function site(string $slug): Site
    {
        return $this->sites()->find($slug) ?? throw new \LogicException('No site ' . $slug);
    }

    private function services(Site $site): ContainerInterface
    {
        return Services::get($this->kernel()->container(), SiteContainerFactory::class)->forSite($site);
    }

    private function registerDeleted(string $slug, string $encryptedDsn, SiteStatus $status = SiteStatus::Deleted): void
    {
        $site = SiteFixture::site($slug, [$slug . '.example'], $status);
        $this->sites()->save(new Site(
            $site->id,
            $site->slug,
            $site->name,
            $status,
            $site->ownerType,
            $site->domains,
            $encryptedDsn,
            $site->storage,
            $site->defaultLocale,
            $site->locales,
            $site->defaultTimezone,
            $site->createdAt,
        ));
    }
}
