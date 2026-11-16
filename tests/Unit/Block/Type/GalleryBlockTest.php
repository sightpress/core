<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\GalleryAltRule;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(GalleryBlock::class)]
#[CoversClass(GalleryAltRule::class)]
#[UsesNamespace('SightPress\Core')]
final class GalleryBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new GalleryBlock();

        self::assertSame(['gallery', 'Gallery', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertStringContainsString('"maxItems":50', (string) json_encode($type->schema()));
        self::assertSame(['images' => []], $type->upgradeData(1, ['images' => []]));
        self::assertInstanceOf(GalleryAltRule::class, $type->a11yRules()[0]);
        self::assertSame([['media' => 1]], GalleryBlock::images(['images' => [['media' => 1], 'x']]));
        self::assertSame([], GalleryBlock::images(['images' => 'x']));
    }

    public function testRendersAListOfFigures(): void
    {
        $fixture = new BlockFixture();
        $one = $fixture->addMedia(variants: []);
        $draft = $fixture->addMedia(published: false);
        $key = $fixture->key($one);
        $type = new GalleryBlock();
        $data = ['images' => [['media' => $one, 'alt' => 'Harbour', 'caption' => 'At dawn'], ['media' => $draft, 'alt' => 'Hidden'], ['media' => $one, 'decorative' => true]]];

        $img = "<img src=\"/media/{$key}/lighthouse.jpg\" srcset=\"/media/{$key}/lighthouse.jpg 1280w\" sizes=\"(max-width: 40rem) 100vw, 20rem\" width=\"1280\" height=\"640\"";
        self::assertSame(
            '<div class="block-gallery"><ul class="block-gallery__items" role="list">'
            . '<li><figure>' . $img . ' alt="Harbour" loading="lazy" decoding="async"><figcaption>At dawn</figcaption></figure></li>'
            . '<li><figure>' . $img . ' alt="" loading="lazy" decoding="async"></figure></li></ul></div>',
            $type->render($data, $fixture->context()),
        );
        self::assertStringEndsWith('</ul><figcaption>Our harbour &amp; town</figcaption></figure>', $type->render($data + ['caption' => 'Our harbour & town'], $fixture->context()));
        self::assertStringStartsWith('<figure class="block-gallery">', $type->render($data + ['caption' => 'x'], $fixture->context()));
        self::assertSame('', $type->render(['images' => [['media' => $draft, 'alt' => 'A']]], $fixture->context()));
    }

    public function testMarkdownAndStructuredData(): void
    {
        $type = new GalleryBlock();
        $context = new PlainBlockContext();
        $data = ['images' => [['media' => 1, 'alt' => 'One'], ['media' => 2, 'decorative' => true]], 'caption' => 'Two pictures'];

        self::assertSame("![One](https://cdn.example/1)\n\n_Two pictures_", $type->renderMarkdown($data, $context));
        self::assertSame(
            ['@type' => 'ImageGallery', 'image' => [['@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/1', 'description' => 'One']], 'description' => 'Two pictures'],
            $type->structuredData($data, $context),
        );
        self::assertSame(['@type' => 'ImageGallery', 'image' => [['@type' => 'ImageObject', 'contentUrl' => 'https://cdn.example/1', 'description' => 'One']]], $type->structuredData(['images' => [['media' => 1, 'alt' => 'One']]], $context));
        self::assertNull($type->structuredData(['images' => [['media' => 2, 'decorative' => true]]], $context));
    }

    public function testChecksEveryPicture(): void
    {
        $issues = new GalleryAltRule()->check(['images' => [['media' => 1, 'alt' => 'Fine'], ['media' => 2], ['media' => 3, 'alt' => 'IMG_0042']]]);

        self::assertSame(
            [['image.alt_missing', '/images/1/alt', A11ySeverity::Error, 'Picture 2: Describe the image, or mark it as decorative.'], ['image.alt_file_name', '/images/2/alt', A11ySeverity::Warning, 'Picture 3: The alt text looks like a file name. Describe what the image shows.']],
            array_map(static fn ($issue): array => [$issue->rule, $issue->field, $issue->severity, $issue->message], $issues),
        );
    }
}
