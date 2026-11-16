<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\ImageAltRule;
use SightPress\Core\Block\Type\ImageBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(ImageBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class ImageBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new ImageBlock();

        self::assertSame(['image', 'Image', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertStringContainsString('"format":"media-image"', (string) json_encode($type->schema()));
        self::assertSame(['media' => 1], $type->upgradeData(1, ['media' => 1]));
        self::assertInstanceOf(ImageAltRule::class, $type->a11yRules()[0]);
    }

    public function testRendersAResponsiveFigureFromTheMediaLibrary(): void
    {
        $fixture = new BlockFixture();
        $id = $fixture->addMedia(fileName: 'red lighthouse.jpg');
        $key = $fixture->media()->find($id)?->key();
        $base = '/media/' . $key;

        $html = new ImageBlock()->render(['media' => $id, 'alt' => 'A red "lighthouse"', 'caption' => ' Taken in 2025 '], $fixture->context());

        self::assertSame(
            '<figure class="block-image"><img src="' . $base . '/red%20lighthouse.jpg"'
            . ' srcset="' . $base . '/320w/red%20lighthouse.jpg 320w, ' . $base . '/640w/red%20lighthouse.jpg 640w, ' . $base . '/red%20lighthouse.jpg 1280w"'
            . ' sizes="(max-width: 60rem) 100vw, 60rem" width="1280" height="640" alt="A red &quot;lighthouse&quot;" loading="lazy" decoding="async">'
            . '<figcaption>Taken in 2025</figcaption></figure>',
            $html,
        );
    }

    public function testDecorativeImagesHaveEmptyAlt(): void
    {
        $fixture = new BlockFixture();
        $id = $fixture->addMedia(variants: []);

        $html = new ImageBlock()->render(['media' => $id, 'alt' => 'Ignored', 'decorative' => true], $fixture->context());

        self::assertStringContainsString('alt=""', $html);
        self::assertStringNotContainsString('srcset', new ImageBlock()->render(['media' => $id], new PlainBlockContext()));
        self::assertStringNotContainsString('figcaption', $html);
    }

    public function testLeavesOutFilesItCannotShow(): void
    {
        $fixture = new BlockFixture();
        $draft = $fixture->addMedia(published: false);
        $document = $fixture->addMedia(kind: MediaKind::Document, fileName: 'report.pdf');
        $type = new ImageBlock();

        self::assertSame('', $type->render(['media' => $draft, 'alt' => 'A'], $fixture->context()));
        self::assertSame('', $type->render(['media' => 'x'], $fixture->context()));
        self::assertSame('', $type->render(['media' => 3], new PlainBlockContext(['3'])));
        self::assertSame('', $type->render(['media' => $document, 'alt' => 'A'], $fixture->context()));
        self::assertSame('<figure class="block-image"><img src="https://cdn.example/3" alt="A" loading="lazy" decoding="async"></figure>', $type->render(['media' => 3, 'alt' => 'A'], new PlainBlockContext()));
    }

    public function testMarkdownAndStructuredDataUseAltAndCaption(): void
    {
        $type = new ImageBlock();
        $context = new PlainBlockContext(['9']);

        self::assertSame('![A \*red\* lighthouse](https://cdn.example/3)', $type->renderMarkdown(['media' => 3, 'alt' => 'A *red* lighthouse'], $context));
        self::assertSame("![A](https://cdn.example/3)\n\n_Taken in 2025_", $type->renderMarkdown(['media' => 3, 'alt' => 'A', 'caption' => 'Taken in 2025'], $context));
        self::assertSame('_Only a caption_', $type->renderMarkdown(['media' => 3, 'decorative' => true, 'caption' => 'Only a caption'], $context));
        self::assertSame('', $type->renderMarkdown(['media' => 3, 'decorative' => true], $context));
        self::assertSame('', $type->renderMarkdown(['media' => 9, 'alt' => 'A'], $context));
        self::assertSame('', $type->renderMarkdown(['media' => 'x', 'alt' => 'A'], $context));

        self::assertSame(['@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/3', 'description' => 'A'], $type->structuredData(['media' => 3, 'alt' => 'A'], $context));
        self::assertSame(['@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/3', 'description' => 'A', 'caption' => 'C'], $type->structuredData(['media' => 3, 'alt' => 'A', 'caption' => 'C'], $context));
        self::assertNull($type->structuredData(['media' => 3, 'decorative' => true], $context));
        self::assertNull($type->structuredData(['media' => 9, 'alt' => 'A'], $context));
        self::assertNull($type->structuredData(['alt' => 'A'], $context));
    }
}
