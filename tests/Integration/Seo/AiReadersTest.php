<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Seo\AiCrawlerPolicy;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * C-6.8 acceptance through the kernel: every page as Markdown (`/{path}.md` and `Accept: text/markdown`), the
 * Markdown alternate in the `<head>`, `llms.txt` and `llms-full.txt` with only the pages search engines may index,
 * and the AI crawler policy in robots.txt.
 */
#[CoversNothing]
final class AiReadersTest extends TestCase
{
    private KernelSite $site;

    private Browser $browser;

    protected function setUp(): void
    {
        $this->site = new KernelSite('ai_readers', environment: 'production');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $now = new DateTimeImmutable();
        $text = static fn (string $html): array => [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]]];
        $home = $editor->create('en-GB', new PageContent('Welcome to Demo', 'home', 'The library by the river.', null, $text('<p>Open <strong>every</strong> day.</p>')));
        $about = $editor->create('en-GB', new PageContent('About [us]', 'about', null, 'Who we are.', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Our story', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Read <a href="/home">our news</a>.</p>']],
        ]));
        $hidden = $editor->create('en-GB', new PageContent('Hidden', 'hidden', 'Not listed.', null, [], null, null, true));
        $members = $editor->create('en-GB', new PageContent('Members', 'members', 'Members only.'));
        $members->setPublic(false, $now);
        foreach ([$home, $about, $hidden, $members] as $page) {
            $page->changeState(PageState::Published, $now);
        }
        Services::get($container, PageRepository::class)->flush();
        $this->browser = new Browser($this->site->kernel, 'https://example.org');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testEveryPageIsThereAsMarkdown(): void
    {
        $markdown = $this->browser->request('GET', '/about.md');
        self::assertSame(200, $markdown->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $markdown->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $markdown->getHeaderLine('Vary'));
        self::assertSame('<https://example.org/about>; rel="canonical"', $markdown->getHeaderLine('Link'));
        self::assertFalse($markdown->hasHeader('X-Robots-Tag'));
        self::assertMatchesRegularExpression("~^# About \\[us\\]\n\n## Our story\n\nRead \\[our news\\]\\(https://example\\.org/home\\)\\.\n\n---\n\nSource: <https://example\\.org/about>  \nLast updated: \\d{4}-\\d\\d-\\d\\d\n$~", (string) $markdown->getBody());

        $negotiated = $this->browser->request('GET', '/about', headers: ['Accept' => 'text/markdown, text/html;q=0.5']);
        self::assertSame('text/markdown; charset=utf-8', $negotiated->getHeaderLine('Content-Type'));
        self::assertSame((string) $markdown->getBody(), (string) $negotiated->getBody());
        $html = $this->browser->request('GET', '/about', headers: ['Accept' => 'text/html,text/markdown;q=0.9']);
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $html->getHeaderLine('Vary'));
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://example.org/about.md">', (string) $html->getBody());

        $home = (string) $this->browser->request('GET', '/index.md')->getBody();
        self::assertStringStartsWith("# Welcome to Demo\n\n> The library by the river.\n\nOpen **every** day.\n", $home);
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://example.org/index.md">', (string) $this->browser->request('GET', '/')->getBody());
        self::assertSame('https://example.org/index.md', $this->browser->request('GET', '/home.md')->getHeaderLine('Location'));
        self::assertSame('https://example.org/about.md', $this->browser->request('GET', '/about/index.md')->getHeaderLine('Location'));
        self::assertSame(302, $this->browser->request('GET', '/members.md')->getStatusCode(), 'members only: sign in first');
        self::assertSame(404, $this->browser->request('GET', '/nowhere.md')->getStatusCode());

        $hidden = $this->browser->request('GET', '/hidden.md');
        self::assertSame('noindex', $hidden->getHeaderLine('X-Robots-Tag'));
        self::assertStringNotContainsString('text/markdown', (string) $this->browser->request('GET', '/hidden')->getBody(), 'no alternate for a page left out of search');
    }

    public function testLlmsTxtListsTheIndexablePages(): void
    {
        $llms = $this->browser->request('GET', '/llms.txt');
        self::assertSame('text/plain; charset=utf-8', $llms->getHeaderLine('Content-Type'));
        self::assertSame(
            "# Demo\n\n> The library by the river.\n\nEvery page of this website is also available as Markdown: add `.md` to its address (`/index.md` for the home page).\n\n## Pages\n\n"
            . "- [Welcome to Demo](https://example.org/index.md): The library by the river.\n"
            . "- [About \\[us\\]](https://example.org/about.md): Who we are.\n\n"
            . "## Optional\n\n- [Full text of every page](https://example.org/llms-full.txt): all the pages above in one Markdown file\n",
            (string) $llms->getBody(),
        );

        $full = (string) $this->browser->request('GET', '/llms-full.txt')->getBody();
        self::assertStringStartsWith("# Demo\n\n> The library by the river.\n\n# Welcome to Demo\n", $full);
        self::assertStringContainsString("\n# About [us]\n", $full);
        self::assertStringNotContainsString('Hidden', $full);
        self::assertStringNotContainsString('Members', $full);
    }

    public function testTheAiCrawlerPolicyGoesIntoRobotsTxt(): void
    {
        self::assertStringNotContainsString('GPTBot', (string) $this->browser->request('GET', '/robots.txt')->getBody(), 'allow_all by default');

        $settings = Services::get($this->site->container(), SiteSettings::class);
        $settings->set(AiCrawlerPolicy::SETTING, AiCrawlerPolicy::SEARCH_ONLY);
        $searchOnly = (string) $this->browser->request('GET', '/robots.txt')->getBody();
        self::assertStringContainsString("# AI crawlers kept out by the site's policy (search_only).\nUser-agent: Amazonbot\nUser-agent: anthropic-ai\n", $searchOnly);
        self::assertStringContainsString("User-agent: GPTBot\n", $searchOnly);
        self::assertStringNotContainsString('OAI-SearchBot', $searchOnly);
        self::assertStringEndsWith("Disallow: /\n\nSitemap: https://example.org/sitemap.xml\n", $searchOnly);

        $settings->set(AiCrawlerPolicy::SETTING, AiCrawlerPolicy::BLOCK_ALL);
        $blockAll = (string) $this->browser->request('GET', '/robots.txt')->getBody();
        self::assertStringContainsString("User-agent: OAI-SearchBot\n", $blockAll);
        self::assertStringContainsString("User-agent: PerplexityBot\n", $blockAll);
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin\n", $blockAll, 'search engines are not affected');
    }
}
