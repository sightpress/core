<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandColours;
use SightPress\Core\Branding\BrandImages;
use SightPress\Core\Branding\BrandingException;
use SightPress\Core\Branding\Initials;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(BrandImages::class)]
#[CoversClass(BrandAsset::class)]
#[UsesClass(BrandColours::class)]
#[UsesClass(Initials::class)]
#[UsesClass(BrandingException::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class BrandImagesTest extends TestCase
{
    private BrandImages $images;

    protected function setUp(): void
    {
        $this->images = new BrandImages();
    }

    public function testAnUploadedIconBecomesTheIconSet(): void
    {
        $files = $this->images->iconSet(MediaFixture::png(600, 600));

        self::assertSame(['icon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'favicon.ico'], array_keys($files));
        foreach (BrandImages::ICON_FILES as $name => $pixels) {
            self::assertSame([$pixels, $pixels, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring($files[$name]), 0, 3), $name);
        }
        self::assertSame(BrandImages::ico($files['icon-32.png'], 32), $files['favicon.ico']);
        self::assertSame($files['icon-32.png'], substr($files['favicon.ico'], 22));
    }

    public function testRefusesIconsThatAreNotSquareOrTooSmall(): void
    {
        foreach ([[600, 500], [300, 300]] as [$width, $height]) {
            try {
                $this->images->iconSet(MediaFixture::jpeg($width, $height));
                self::fail('Accepted an icon of ' . $width . ' × ' . $height);
            } catch (BrandingException $exception) {
                self::assertSame(sprintf('The icon must be a square picture of at least 512 × 512 pixels. This one is %d × %d pixels.', $width, $height), $exception->getMessage());
            }
        }
    }

    public function testAShareImageIsCutTo1200By630(): void
    {
        $jpeg = $this->images->shareImage(MediaFixture::jpeg(2400, 1250));

        self::assertSame([1200, 630, IMAGETYPE_JPEG], array_slice((array) getimagesizefromstring($jpeg), 0, 3));
        foreach ([[800, 420], [1200, 900]] as [$width, $height]) {
            try {
                $this->images->shareImage(MediaFixture::png($width, $height));
                self::fail('Accepted a share image of ' . $width . ' × ' . $height);
            } catch (BrandingException $exception) {
                self::assertStringEndsWith(sprintf('This one is %d × %d pixels.', $width, $height), $exception->getMessage());
            }
        }
    }

    public function testARasterLogoIsKeptAtMost1000PixelsWide(): void
    {
        [$png, $width, $height] = $this->images->rasterLogo(MediaFixture::webp(2000, 500));
        self::assertSame([1000, 250, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring($png), 0, 3));
        self::assertSame([1000, 250], [$width, $height]);
        self::assertSame([400, 100], array_slice($this->images->rasterLogo(MediaFixture::png(400, 100)), 1));

        $this->expectExceptionMessage('The logo must be at least as wide as it is high, and at most 8 times as wide. This one is 100 × 300.');
        $this->images->rasterLogo(MediaFixture::png(100, 300));
    }

    public function testLogoProportions(): void
    {
        BrandImages::checkLogoRatio(800, 100);
        BrandImages::checkLogoRatio(64, 64);

        $this->expectExceptionMessage('This one is 900.5 × 100.');
        BrandImages::checkLogoRatio(900.5, 100);
    }

    public function testRefusesFilesThatAreNotPicturesTooLargeOrBroken(): void
    {
        $header = static fn (int $width, int $height): string => "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0);
        foreach ([
            [str_repeat('x', BrandImages::MAX_BYTES + 1), BrandAsset::Icon, 'The icon must be 5 MB or smaller.'],
            ['<svg xmlns="http://www.w3.org/2000/svg"/>', BrandAsset::Icon, 'The icon must be a PNG, JPEG or WebP picture.'],
            [MediaFixture::gif(), BrandAsset::Logo, 'The logo must be a SVG, PNG, JPEG or WebP picture.'],
            [$header(0, 0), BrandAsset::ShareImage, 'The share image can\'t be read. It may be damaged; save it again and try the new file.'],
            [$header(10000, 5000), BrandAsset::ShareImage, 'The share image is 10000 × 5000 pixels, which is too large. Make it smaller than 40 million pixels.'],
        ] as [$bytes, $asset, $message]) {
            try {
                $this->images->size($bytes, $asset);
                self::fail('Accepted: ' . $message);
            } catch (BrandingException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $truncated = substr(MediaFixture::png(600, 600), 0, 60);
        $this->expectExceptionMessage('This picture can\'t be read.');
        $this->images->iconSet($truncated);
    }

    public function testGeneratesAnIconWithTheInitials(): void
    {
        $files = $this->images->initialsIconSet('Riverside Library');

        self::assertSame(['icon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'favicon.ico', 'icon.svg'], array_keys($files));
        self::assertSame([512, 512], array_slice((array) getimagesizefromstring($files['icon-512.png']), 0, 2));
        self::assertStringContainsString('fill="#1f4e79"', $files['icon.svg']);
        self::assertStringContainsString('font-size="220" font-weight="700">RL</text>', $files['icon.svg']);
        $image = imagecreatefromstring($files['icon-512.png']);
        self::assertNotFalse($image);
        self::assertSame(BrandColours::rgb(BrandColours::BACKGROUND), array_values(array_slice(imagecolorsforindex($image, (int) imagecolorat($image, 5, 5)), 0, 3)));
        self::assertTrue($this->hasColour($image, BrandColours::FOREGROUND), 'the initials are drawn');

        $chinese = $this->images->initialsIconSet('李 小龙');
        self::assertStringContainsString('font-size="220" font-weight="700">李小</text>', $chinese['icon.svg']);
        $plain = imagecreatefromstring($chinese['icon-192.png']);
        self::assertNotFalse($plain);
        self::assertFalse($this->hasColour($plain, BrandColours::FOREGROUND), 'no font for these letters: a plain icon');
        self::assertStringContainsString('font-size="280" font-weight="700">D</text>', $this->images->initialsIconSet('Demo')['icon.svg']);
    }

    public function testGeneratesAShareImageAndAWordmarkWithTheName(): void
    {
        $png = $this->images->generatedShareImage('Riverside Library and Community Hub for Everyone in the Valley and Beyond the Hills');
        self::assertSame([1200, 630], array_slice((array) getimagesizefromstring($png), 0, 2));
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        self::assertTrue($this->hasColour($image, BrandColours::FOREGROUND));
        self::assertSame([1200, 630], array_slice((array) getimagesizefromstring($this->images->generatedShareImage('李小龙')), 0, 2));

        [$svg, $width, $height] = BrandImages::wordmark('Tom & Jerry');
        self::assertSame([266, 64], [$width, $height]);
        self::assertStringContainsString('aria-label="Tom &amp; Jerry"><title>Tom &amp; Jerry</title>', $svg);
        self::assertSame(64, BrandImages::wordmark('A')[1]);
        self::assertSame('image/png', BrandImages::mimeType(MediaFixture::png()));
    }

    private function hasColour(\GdImage $image, string $hex): bool
    {
        [$red, $green, $blue] = BrandColours::rgb($hex);
        for ($x = 0; $x < imagesx($image); $x += 2) {
            for ($y = 0; $y < imagesy($image); $y += 2) {
                $colour = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));
                if ($colour['red'] === $red && $colour['green'] === $green && $colour['blue'] === $blue) {
                    return true;
                }
            }
        }

        return false;
    }
}
