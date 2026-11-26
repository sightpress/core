<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Preview\PreviewContent;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\ThemeException;
use SightPress\Theme\Preview\PreviewPage;

#[CoversClass(PreviewContent::class)]
#[UsesNamespace('SightPress\Core\Theme')]
final class PreviewContentTest extends TestCase
{
    private const array MEDIA = ['picture' => 1, 'captions' => 2, 'video' => 3, 'audio' => 4, 'document' => 5];

    public function testFillsInThePageOfTheContract(): void
    {
        $content = PreviewContent::build(self::theme(), self::MEDIA);

        self::assertSame('Riverside Reading Room', $content->siteName);
        self::assertNotSame('', $content->tagline);
        self::assertSame(['label' => 'Download', 'accessible_name' => 'Download Harbour', 'url' => 'https://acme.example/harbour'], $content->button);
        self::assertSame(['text', 'media', 'layout', 'sound', 'contact', 'around'], array_column($content->sections, 'id'));
        self::assertSame(['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Text and buttons', 'level' => 2]], $content->blocks[0]);
        $json = (string) json_encode($content->blocks);
        self::assertStringNotContainsString('{{', $json);
        self::assertStringNotContainsString('"@', $json);
        self::assertStringContainsString('"media":1', $json);
        self::assertStringContainsString('"captions":2', $json);
        self::assertCount(count($content->sections) + 13, $content->blocks);
    }

    public function testEveryCoreSampleFileIsNeeded(): void
    {
        $this->expectExceptionObject(new ThemeException('The preview page names the sample file "@document", which core doesn\'t have.'));
        PreviewContent::build(self::theme(), array_diff_key(self::MEDIA, ['document' => true]));
    }

    public function testRefusesAPageThatIsNotJson(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $file = $directory->write('page.json', '{');
            $this->expectException(ThemeException::class);
            $this->expectExceptionMessage('The preview page ' . $file . ' can\'t be read');
            PreviewContent::build(self::theme(), self::MEDIA, $file);
        } finally {
            $directory->remove();
        }
    }

    public function testUsesThePageOfTheContractByDefault(): void
    {
        self::assertEquals(PreviewContent::build(self::theme(), self::MEDIA, PreviewPage::file()), PreviewContent::build(self::theme(), self::MEDIA));
    }

    private static function theme(): PreviewTheme
    {
        return new PreviewTheme('acme-harbour', 'Harbour', 'Calm blues.', '1.0.0', 'https://acme.example/harbour', '/themes/harbour');
    }
}
