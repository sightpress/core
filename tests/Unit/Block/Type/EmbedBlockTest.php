<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\EmbedBlock;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Module\Block\BlockOption;

#[CoversClass(EmbedBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class EmbedBlockTest extends TestCase
{
    private const array MAP = ['url' => 'https://www.openstreetmap.org/?mlat=38.7223&mlon=-9.1393', 'title' => 'Map of the library'];

    private const array VIDEO = ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'A tour <of> the library'];

    private AuthFixture $auth;

    private EmbedBlock $type;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->type = new EmbedBlock($this->auth->templates);
    }

    public function testDescribesItself(): void
    {
        self::assertSame(['embed', 'Video or map from another site', 1, []], [$this->type->name(), $this->type->label(), $this->type->version(), $this->type->a11yRules()]);
        self::assertSame(['url', 'title'], $this->type->schema()['required']);
        self::assertSame(self::MAP, $this->type->upgradeData(1, self::MAP));
        self::assertSame([BlockOption::Width, BlockOption::Spacing], $this->type->styles()->options);
        self::assertNull($this->type->structuredData(self::MAP, new PlainBlockContext()));
    }

    public function testShowsANoticeAndALinkButLoadsNothingFromTheProvider(): void
    {
        $html = $this->type->render(self::VIDEO, $this->context());

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<figure class="block-embed block-embed--youtube" data-embed>', $html);
        self::assertStringContainsString('This video is on YouTube. Showing it here lets YouTube see that you visited this page.', $html);
        self::assertStringContainsString('<a href="https://policies.google.com/privacy">YouTube’s privacy policy</a>', $html);
        self::assertStringContainsString('data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('data-embed-title="A tour &lt;of&gt; the library" hidden>Show the video: A tour &lt;of&gt; the library</button>', $html);
        self::assertStringContainsString('<a href="https://youtu.be/dQw4w9WgXcQ">Watch on YouTube: A tour &lt;of&gt; the library</a>', $html);
        self::assertStringContainsString('<figcaption>A tour &lt;of&gt; the library</figcaption>', $html);
        self::assertStringContainsString('<script type="module" src="/assets/js/embed.js"></script>', $html);

        $map = $this->type->render(self::MAP, $this->context());
        self::assertStringContainsString('This map is on OpenStreetMap.', $map);
        self::assertStringContainsString('>Show the map: Map of the library</button>', $map);
        self::assertStringContainsString('>Open the map on OpenStreetMap: Map of the library</a>', $map);
    }

    public function testWithoutAReaderOrAKnownLinkItIsALinkOrNothing(): void
    {
        self::assertSame('<p class="block-embed"><a href="https://youtu.be/dQw4w9WgXcQ">A tour &lt;of&gt; the library</a> (YouTube)</p>', $this->type->render(self::VIDEO, new PlainBlockContext()));
        self::assertSame('', $this->type->render(['url' => 'https://example.org/video', 'title' => 'Elsewhere'], $this->context()));
        self::assertSame('', $this->type->render(['url' => self::VIDEO['url'], 'title' => ''], $this->context()));
    }

    public function testMarkdownIsTheLink(): void
    {
        self::assertSame('[A tour \<of\> the library](https://youtu.be/dQw4w9WgXcQ) (YouTube)', $this->type->renderMarkdown(self::VIDEO, new PlainBlockContext()));
        self::assertSame('[Map of the library](https://www.openstreetmap.org/?mlat=38.7223&mlon=-9.1393) (OpenStreetMap)', $this->type->renderMarkdown(self::MAP, new PlainBlockContext()));
        self::assertSame('', $this->type->renderMarkdown(['url' => 'https://example.org', 'title' => 'X'], new PlainBlockContext()));
    }

    private function context(): \SightPress\Module\Block\BlockContext
    {
        return new BlockFixture([], $this->auth->database)->contexts()->create('en-GB', 'https://demo.example', $this->auth->request('GET', 'https://demo.example/visit'));
    }
}
