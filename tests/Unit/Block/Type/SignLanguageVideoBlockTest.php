<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\SignLanguageVideoBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(SignLanguageVideoBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class SignLanguageVideoBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new SignLanguageVideoBlock();

        self::assertSame(['sign_language_video', 'Sign language video', 1, []], [$type->name(), $type->label(), $type->version(), $type->a11yRules()]);
        self::assertSame(['media', 'title', 'sign_language', 'captions'], $type->schema()['required'], 'captions are still required');
        $properties = $type->schema()['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['sign_language'] ?? null);
        self::assertSame(array_keys(SignLanguageVideoBlock::LANGUAGES), $properties['sign_language']['enum'] ?? null);
        self::assertSame(['title' => 'A'], $type->upgradeData(1, ['title' => 'A']));
        self::assertCount(2, $type->styles()->options);
    }

    public function testShowsTheVideoWithItsSignLanguageTaggedAndNamed(): void
    {
        $fixture = new BlockFixture();
        $video = $fixture->addMedia(kind: MediaKind::Video, fileName: 'welcome.mp4');
        $captions = $fixture->addCaptions();
        $type = new SignLanguageVideoBlock();
        $context = $fixture->context();
        $data = ['media' => $video, 'title' => 'Welcome', 'sign_language' => 'british', 'captions' => $captions, 'transcript' => 'Welcome to the library.'];

        $html = $type->render($data, $context);
        self::assertStringStartsWith('<figure class="block-video block-sign-language-video" data-sign-language="bfi"><video controls', $html);
        self::assertStringContainsString('<track kind="captions"', $html);
        self::assertStringContainsString('<figcaption>Welcome <span class="block-sign-language-video__language">(British Sign Language (BSL))</span></figcaption>', $html);
        self::assertSame(
            '[Video in British Sign Language (BSL): Welcome](https://demo.example/media/' . $fixture->key($video) . "/welcome.mp4)\n\nTranscript:\n\nWelcome to the library.",
            $type->renderMarkdown($data, $context),
        );
        $node = $type->structuredData($data, $context);
        self::assertSame(['VideoObject', 'bfi', 'Welcome to the library.'], [$node['@type'] ?? null, $node['inLanguage'] ?? null, $node['transcript'] ?? null]);

        $unknown = ['sign_language' => 'klingon'] + $data;
        self::assertStringStartsWith('<figure class="block-video"><video', $type->render($unknown, $context));
        self::assertStringStartsWith('[Video: Welcome]', $type->renderMarkdown($unknown, $context));
        self::assertArrayNotHasKey('inLanguage', $type->structuredData($unknown, $context) ?? []);
    }

    public function testShowsNothingWithoutTheVideo(): void
    {
        $type = new SignLanguageVideoBlock();
        $data = ['media' => 999, 'title' => 'Welcome', 'sign_language' => 'irish', 'captions' => 1];

        self::assertSame('', $type->render($data, new BlockFixture()->context()));
        self::assertSame('', $type->renderMarkdown($data, new BlockFixture()->context()));
        self::assertNull($type->structuredData($data, new BlockFixture()->context()));
        self::assertSame('', $type->renderMarkdown(['title' => 'X'], new PlainBlockContext()));
    }
}
