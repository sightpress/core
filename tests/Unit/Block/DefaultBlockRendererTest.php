<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\DefaultBlockRenderer;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Core\Tests\Support\VersionedBlockType;

#[CoversClass(DefaultBlockRenderer::class)]
#[UsesNamespace('SightPress\Core')]
final class DefaultBlockRendererTest extends TestCase
{
    public function testRendersHtmlMarkdownAndStructuredData(): void
    {
        $fixture = new BlockFixture([new VersionedBlockType()]);
        $picture = $fixture->addMedia(variants: []);
        $blocks = [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Visit', 'level' => 2]],
            ['type' => 'acme.badge', 'version' => 1, 'data' => ['text' => 'Old']],
            ['type' => 'acme.badge', 'version' => 9, 'data' => ['label' => 'Future']],
            ['type' => 'acme.gone', 'version' => 1, 'data' => []],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 999_999, 'alt' => 'Missing']],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'Lighthouse']], ['type' => 'acme.gone', 'version' => 1, 'data' => []]]],
                ['blocks' => [['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'New']]]],
            ]]],
        ];
        $renderer = $fixture->renderer();
        $context = $fixture->context();

        $html = $renderer->html($blocks, $context);
        self::assertStringStartsWith("<h2 class=\"block-heading\">Visit</h2>\n<span class=\"badge\">Old</span>\n<div class=\"block-columns block-columns--2\"><div class=\"block-column\"><figure class=\"block-image\"><img src=\"/media/", $html);
        self::assertStringEndsWith('<div class="block-column"><span class="badge">New</span></div></div>', $html);
        self::assertStringNotContainsString('Future', $html);

        $key = $fixture->media()->find($picture)?->key();
        self::assertSame("## Visit\n\n**Old**\n\n![Lighthouse](https://demo.example/media/{$key}/lighthouse.jpg)\n\n**New**", $renderer->markdown($blocks, $context));
        self::assertSame(
            [
                ['@type' => 'Thing', 'name' => 'Old'],
                ['@type' => 'ImageObject', 'contentUrl' => "https://demo.example/media/{$key}/lighthouse.jpg", 'description' => 'Lighthouse'],
                ['@type' => 'Thing', 'name' => 'New'],
            ],
            $renderer->structuredData($blocks, $context),
        );
    }

    public function testWorksWithAnyContext(): void
    {
        $renderer = new BlockFixture()->renderer();

        self::assertSame('', $renderer->html([], new PlainBlockContext()));
        self::assertSame('![A](https://cdn.example/4)', $renderer->markdown([['type' => 'image', 'version' => 1, 'data' => ['media' => 4, 'alt' => 'A']]], new PlainBlockContext()));
        self::assertSame([['@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/4', 'description' => 'A']], $renderer->structuredData([['type' => 'image', 'version' => 1, 'data' => ['media' => 4, 'alt' => 'A']]], new PlainBlockContext()));
    }
}
