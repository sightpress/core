<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;

/**
 * Requests reach the right site by host name, each site gets its own container and database, and sites that
 * aren't enabled answer with the right status (ADR-0003).
 */
#[CoversNothing]
final class SiteResolutionTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject(sites: [
            'demo' => SiteFixture::entry('demo', [
                'domains' => ['demo.example', 'www.demo.example'],
                'db_dsn' => SiteFixture::secretBox()->encrypt(self::dsn('demo')),
            ]),
            'blog' => SiteFixture::entry('blog', [
                'db_dsn' => SiteFixture::secretBox()->encrypt(self::dsn('blog')),
            ]),
            'paused' => SiteFixture::entry('paused', ['status' => 'disabled']),
            'archive' => SiteFixture::entry('archive', ['status' => 'archived']),
            'old' => SiteFixture::entry('old', ['status' => 'deleted']),
        ]);
        // Without modules: a route added after booting would come after the public pages' catch-all route.
        $this->kernel = Kernel::fromConfig(
            new \SightPress\Core\Tenant\TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [],
        );

        // Each request writes a row to its site's database and answers with the site, the rows it can see and
        // whether the entity manager shares the site's connection.
        $this->kernel->app()->get('/whoami', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $context = SiteContext::fromRequest($request);
            self::assertNotNull($context);
            $connection = Services::get($context->container, Connection::class);
            $schema = $connection->createSchemaManager();
            if (!$schema->tablesExist(['visits'])) {
                $table = new Table('visits');
                $table->addColumn('site', 'string', ['length' => 63]);
                $schema->createTable($table);
            }
            $connection->insert('visits', ['site' => $context->site->slug]);
            $sites = $connection->createQueryBuilder()->select('site')->from('visits')->fetchFirstColumn();
            $sameConnection = Services::get($context->container, EntityManagerInterface::class)->getConnection() === $connection;

            $response->getBody()->write(sprintf(
                '%s:%s:%s',
                $context->site->slug,
                implode(',', array_map(static fn (mixed $site): string => is_string($site) ? $site : '?', $sites)),
                $sameConnection ? 'shared' : 'separate',
            ));

            return $response;
        });
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testEachSiteUsesItsOwnDatabase(): void
    {
        self::assertSame('demo:demo:shared', $this->body('http://demo.example/whoami'));
        self::assertSame('demo:demo,demo:shared', $this->body('https://WWW.demo.example/whoami'));
        self::assertSame('blog:blog:shared', $this->body('http://blog.example/whoami'));
    }

    public function testSitesThatAreNotEnabledDoNotAnswer(): void
    {
        $paused = $this->get('http://paused.example/whoami');
        self::assertSame(503, $paused->getStatusCode());
        self::assertSame('3600', $paused->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Try again in 60 minutes.', (string) $paused->getBody());

        self::assertSame(410, $this->get('http://archive.example/whoami')->getStatusCode());
        self::assertSame(404, $this->get('http://old.example/whoami')->getStatusCode());

        $unknown = $this->get('http://shop.example/whoami');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertStringContainsString('<h1>Page not found</h1>', (string) $unknown->getBody());
    }

    /**
     * SQLite in memory, or a new database on the DB matrix server.
     */
    private static function dsn(string $name): string
    {
        return TestDatabases::engine() === 'sqlite' ? 'sqlite:///:memory:' : TestDatabases::dsn($name, sys_get_temp_dir());
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest('GET', $uri));
    }

    private function body(string $uri): string
    {
        $response = $this->get($uri);
        self::assertSame(200, $response->getStatusCode(), $this->project->log());

        return (string) $response->getBody();
    }
}
