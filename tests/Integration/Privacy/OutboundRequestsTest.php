<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Privacy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Http\OutboundHttp;
use SightPress\Core\Infrastructure\Http\OutboundRequestRefused;
use SightPress\Core\Infrastructure\Privacy\PrivacyExtensions;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * No third parties by default (GD-1, D49): a fresh site with default settings renders pages, search, the sign-in
 * and registration pages and sends a contact form without one outgoing request; a host that nothing declares is
 * refused with a clear error; turning the AI assistant on allows its provider's host, and only that.
 */
#[CoversNothing]
final class OutboundRequestsTest extends TestCase
{
    private KernelSite $site;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('outbound', tenant: ['assistant' => ['provider' => 'anthropic', 'model' => 'claude-test']], variables: ['ASSISTANT_API_KEY' => 'key-for-tests']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(\DI\Container::class, $tenant);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->sent[] = $method . ' ' . $url;

            return new MockResponse('{}');
        }));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAFreshSiteSendsNothingToAnyone(): void
    {
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $form = [['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'office@example.org']]];
        $home = $editor->create('en-GB', new PageContent('Home', null, null, null, $form));
        $home->changeState(PageState::Published, new \DateTimeImmutable());
        Services::get($container, PageRepository::class)->flush();
        $browser = new Browser($this->site->kernel, 'https://example.org');

        foreach (['/', '/search?q=home', '/login', '/register', '/sitemap.xml', '/robots.txt', '/llms.txt'] as $path) {
            self::assertLessThan(500, $browser->request('GET', $path)->getStatusCode(), $path);
        }
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)">/', (string) $browser->request('GET', '/')->getBody(), $hidden, PREG_SET_ORDER);
        $fields = [ContactForm::HONEYPOT => ''];
        foreach ($hidden as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }
        $sent = $browser->request('POST', ContactForm::PATH, ['name' => 'Ada', 'email' => 'ada@example.org', 'message' => 'Hello'] + $fields);
        self::assertSame(303, $sent->getStatusCode());
        self::assertStringContainsString('Thank you. Your message has been sent.', (string) $browser->request('GET', '/')->getBody());

        self::assertSame([], $this->sent);
        self::assertSame([], Services::get($container, PrivacyExtensions::class)->processors(), 'no third party is declared');
    }

    public function testAHostThatNothingDeclaresIsRefused(): void
    {
        $http = Services::get($this->site->container(), HttpClientInterface::class);
        self::assertInstanceOf(OutboundHttp::class, $http);

        try {
            $http->request('GET', 'https://tracker.example/pixel');
            self::fail('The request was sent.');
        } catch (OutboundRequestRefused $refused) {
            self::assertSame('The request to tracker.example was refused: no service the site uses declares this address. Set up and turn on the feature that needs it, or ask whoever runs the site to allow it.', $refused->getMessage());
        }
        self::assertSame([], $this->sent);
    }

    public function testTurningTheAssistantOnAllowsOnlyItsProvider(): void
    {
        $http = Services::get($this->site->container(), HttpClientInterface::class);
        self::assertInstanceOf(OutboundHttp::class, $http);
        self::assertFalse($http->hosts()->allows('api.anthropic.com'), 'the assistant is off');

        Services::get($this->site->kernel->container(), SiteModules::class)->turnOn($this->site->site(), 'assistant', null);
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');
        $container = $this->site->container();
        $http = Services::get($container, HttpClientInterface::class);
        self::assertInstanceOf(OutboundHttp::class, $http);

        self::assertSame(['assistant.anthropic'], array_keys(Services::get($container, PrivacyExtensions::class)->processors()));
        self::assertTrue($http->hosts()->allows('api.anthropic.com'));
        self::assertFalse($http->hosts()->allows('api.openai.com'));
        $http->request('POST', 'https://api.anthropic.com/v1/messages')->getStatusCode();
        self::assertSame(['POST https://api.anthropic.com/v1/messages'], $this->sent);
    }
}
