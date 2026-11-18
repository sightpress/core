<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Seo\Http\IndexNowKeyHandler;
use SightPress\Core\Seo\IndexNow;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\PageFixture;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(IndexNow::class)]
#[CoversClass(IndexNowKeyHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class IndexNowTest extends TestCase
{
    private PageFixture $pages;

    /** @var list<array{string, string, array<mixed>}> */
    private array $requests = [];

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    protected function setUp(): void
    {
        $this->pages = new PageFixture();
        $this->logged = new \ArrayObject();
    }

    public function testIsOffByDefaultAndContactsNobody(): void
    {
        $page = $this->pages->published('About');

        $this->indexNow('production')(new PageTransitioned((int) $page->id(), PageTransition::Publish, PageState::Approved, PageState::Published, 1));

        self::assertSame([], $this->requests);
        $this->expectException(HttpNotFoundException::class);
        new IndexNowKeyHandler($this->indexNow('production'), new Psr17Factory())->handle(new ServerRequest('GET', '/indexnow-key.txt'));
    }

    public function testTellsSearchEnginesAboutPagesThatArePublishedOrTakenOffTheSiteInProduction(): void
    {
        $this->pages->database->settings()->set(IndexNow::SETTING, '1');
        $about = $this->pages->published('About');
        $indexNow = $this->indexNow('production');

        $indexNow(new PageTransitioned((int) $about->id(), PageTransition::Publish, PageState::Approved, PageState::Published, 1));
        $indexNow(new PageTransitioned((int) $about->id(), PageTransition::Unpublish, PageState::Published, PageState::Draft, 1));
        $indexNow(new PageTransitioned((int) $about->id(), PageTransition::Submit, PageState::Draft, PageState::InReview, 1));
        $indexNow(new PageTransitioned((int) $about->id(), PageTransition::Archive, PageState::InReview, PageState::Archived, 1));
        $indexNow(new PageTransitioned((int) $about->id(), PageTransition::Trash, PageState::Published, PageState::Trash, 1));
        $indexNow(new PageTransitioned(999, PageTransition::Publish, PageState::Approved, PageState::Published, 1));

        self::assertCount(3, $this->requests, 'only steps to or from published, and only pages that exist');
        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['POST', IndexNow::ENDPOINT], [$method, $url]);
        $key = $indexNow->key();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        self::assertSame($key, $indexNow->key(), 'the key is kept');
        self::assertSame(json_encode(['host' => 'demo.example', 'key' => $key, 'keyLocation' => 'https://demo.example/indexnow-key.txt', 'urlList' => ['https://demo.example/']]), $options['body']);

        $response = new IndexNowKeyHandler($indexNow, new Psr17Factory())->handle(new ServerRequest('GET', '/indexnow-key.txt'));
        self::assertSame([$key, 'text/plain; charset=utf-8', 'noindex'], [(string) $response->getBody(), $response->getHeaderLine('Content-Type'), $response->getHeaderLine('X-Robots-Tag')]);

        $this->requests = [];
        $this->indexNow('test')(new PageTransitioned((int) $about->id(), PageTransition::Publish, PageState::Approved, PageState::Published, 1));
        self::assertSame([], $this->requests, 'never outside production');
    }

    public function testAFailedPingIsLoggedAndPublishingGoesOn(): void
    {
        $this->pages->database->settings()->set(IndexNow::SETTING, '1');
        $this->pages->database->settings()->set(IndexNow::KEY_SETTING, 'not-a-key');
        $about = $this->pages->published('About');

        $this->indexNow('production', fail: true)(new PageTransitioned((int) $about->id(), PageTransition::Publish, PageState::Approved, PageState::Published, 1));

        self::assertSame(['IndexNow could not be told about a page.'], $this->logged->getArrayCopy());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $this->pages->database->settings()->get(IndexNow::KEY_SETTING), 'a bad key is replaced');
    }

    private function indexNow(string $environment, bool $fail = false): IndexNow
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($fail): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $fail ? new MockResponse('', ['error' => 'no network']) : new MockResponse('', ['http_code' => 202]);
        });
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged->append((string) $message);
            }
        };

        return new IndexNow(
            $this->pages->database->settings(),
            PageFixture::site(['en-GB']),
            new PageLinkQuery($this->pages->database->entityManager, $this->pages->addresses(), $this->pages->database->settings()),
            $client,
            TenantConfig::fromArray([], '/srv/app', ['APP_ENV' => $environment]),
            $logger,
        );
    }
}
