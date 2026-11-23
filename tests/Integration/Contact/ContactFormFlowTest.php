<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Contact;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\PageBlocksHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * A visitor sends a contact form through the whole kernel: the page is rendered with the visitor's session and CSRF
 * token, the form is sent, refused with errors, sent again and the page then says it was sent. Without the CSRF
 * token, or from a page that isn't published, it is refused.
 */
#[CoversNothing]
final class ContactFormFlowTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    private Browser $browser;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $dsn = TestDatabases::dsn('contact', $this->project->path());
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 2,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => ['example.org'],
                'db_dsn' => SiteFixture::secretBox()->encrypt($dsn),
            ])],
        ], true) . ';');
        $pages = new FakeModule('acme.pages', register: static function (ModuleRegistrar $r): void {
            $r->services()->autowire(PageBlocksHandler::class);
            $r->routes()->add(new Route('acme.contact', '/contact', PageBlocksHandler::class));
            $r->routes()->add(new Route('acme.draft', '/draft', PageBlocksHandler::class));
        });
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [...CoreModules::candidates(), new ModuleCandidate($pages, 'acme/pages')],
        );
        $site = $this->site();
        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->kernel->modules())),
            new ExistingConnection(Services::get($site, Connection::class)),
        );
        $dependencies->getMetadataStorage()->ensureInitialized();
        $dependencies->getMigrator()->migrate(
            $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
                $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
            ),
            new MigratorConfiguration(),
        );

        $editor = Services::get($site, PageEditor::class);
        $form = [['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'office@example.org']]];
        $editor->create('en-GB', new PageContent('Home'));
        $contact = $editor->create('en-GB', new PageContent('Contact', null, null, null, $form));
        $contact->changeState(PageState::Published, new \DateTimeImmutable());
        Services::get($site, PageRepository::class)->flush();
        $editor->create('en-GB', new PageContent('Draft', null, null, null, $form));
        $this->browser = new Browser($this->kernel, 'https://example.org');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAVisitorSendsAMessage(): void
    {
        $page = $this->browser->request('GET', '/contact');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringNotContainsString('office@example.org', (string) $page->getBody());

        $refused = $this->send($page, ['name' => 'Ada', 'email' => 'nope', 'message' => 'Hello']);
        self::assertSame(303, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertSame('/contact', $refused->getHeaderLine('Location'));
        $again = $this->browser->request('GET', '/contact');
        self::assertStringContainsString('error-summary', (string) $again->getBody());
        self::assertStringContainsString('>Hello</textarea>', (string) $again->getBody());

        $sent = $this->send($again, ['name' => 'Ada', 'email' => 'ada@example.org', 'message' => 'Hello']);
        self::assertSame(303, $sent->getStatusCode());
        self::assertStringContainsString('Thank you. Your message has been sent.', (string) $this->browser->request('GET', '/contact')->getBody());
        self::assertStringContainsString('<form', (string) $this->browser->request('GET', '/contact')->getBody(), 'the message shows once');
    }

    public function testRefusesFormsWithoutTheTokenOrFromAPageThatIsNotPublished(): void
    {
        $id = ContactForm::id(['recipient' => 'office@example.org']);

        self::assertSame(403, $this->browser->request('POST', '/contact-form', ['form' => $id, 'return' => '/contact', 'name' => 'A', 'email' => 'a@example.org', 'message' => 'Hi'])->getStatusCode());
        self::assertSame(404, $this->browser->request('GET', '/draft')->getStatusCode());
        $page = $this->browser->request('GET', '/contact');
        self::assertSame(404, $this->send($page, ['return' => '/draft', 'name' => 'A', 'email' => 'a@example.org', 'message' => 'Hi'])->getStatusCode());
    }

    /**
     * Sends the contact form on a page with its hidden fields and the given fields.
     *
     * @param array<string, string> $fields
     */
    private function send(ResponseInterface $page, array $fields): ResponseInterface
    {
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)">/', (string) $page->getBody(), $hidden, PREG_SET_ORDER);
        $body = [];
        foreach ($hidden as [, $name, $value]) {
            $body[$name] = html_entity_decode($value);
        }

        return $this->browser->request('POST', '/contact-form', $fields + $body + [ContactForm::HONEYPOT => '']);
    }

    private function site(): ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);

        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($site);
    }
}
