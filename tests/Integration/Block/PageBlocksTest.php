<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Block;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;

/**
 * Blocks through a real site container: a page saved with every core block type (rich text cleaned, a real
 * uploaded picture), read back from a fresh entity manager and rendered as HTML, Markdown and schema.org nodes.
 */
#[CoversNothing]
final class PageBlocksTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('blocks');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testSavesAndRendersEveryCoreBlockType(): void
    {
        $container = $this->site->container();
        $source = $this->site->project->directory->write('upload/photo.jpg', MediaFixture::jpeg(1300, 650));
        $media = Services::get($container, MediaUploader::class)->upload($source, 'Lighthouse.jpg', 1)->media;
        $media->publish(new DateTimeImmutable());
        Services::get($container, MediaRepository::class)->save($media);
        $picture = (int) $media->id();

        $editor = Services::get($container, PageEditor::class);
        $page = $editor->create('en-GB', new PageContent('Visit', null, null, null, [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Opening times', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Open <strong>daily</strong>. <a href="/contact" onclick="x()">Contact us</a>.</p><script>alert(1)</script>']],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'The red lighthouse at dusk']]]],
                ['blocks' => [['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Book a visit', 'url' => '/book']]]],
            ]]],
        ]), null, 1);

        Services::get($container, EntityManagerInterface::class)->clear();
        $found = Services::get($container, PageRepository::class)->find((int) $page->id());
        self::assertNotNull($found);
        $blocks = $found->translation('en-GB')?->blocks() ?? [];
        $context = Services::get($container, BlockContexts::class)->create('en-GB', 'https://blocks.example');
        $renderer = Services::get($container, BlockRenderer::class);

        $html = $renderer->html($blocks, $context);
        self::assertStringContainsString('<div class="block-rich-text"><p>Open <strong>daily</strong>. <a href="/contact">Contact us</a>.</p></div>', $html);
        self::assertStringNotContainsString('script', $html);
        self::assertStringContainsString('alt="The red lighthouse at dusk"', $html);
        self::assertStringContainsString('width="1300" height="650"', $html);
        self::assertStringContainsString('<a class="button" href="/book">Book a visit</a>', $html);

        $markdown = $renderer->markdown($blocks, $context);
        self::assertStringStartsWith("## Opening times\n\nOpen **daily**. [Contact us](https://blocks.example/contact).\n\n![The red lighthouse at dusk](https://blocks.example/media/", $markdown);
        self::assertStringEndsWith('[Book a visit](https://blocks.example/book)', $markdown);

        $nodes = $renderer->structuredData($blocks, $context);
        self::assertSame('ImageObject', $nodes[0]['@type'] ?? null);

        $this->expectException(InvalidBlocks::class);
        $editor->save($found, 'en-GB', new PageContent('Visit', null, null, null, [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture + 1000]]]), 1);
    }
}
