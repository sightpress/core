<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandingException;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Branding\BrandingState;
use SightPress\Core\Tests\Support\BrandingFixture;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(BrandingService::class)]
#[CoversClass(BrandingState::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingServiceTest extends TestCase
{
    private BrandingFixture $fixture;

    private BrandingService $branding;

    protected function setUp(): void
    {
        $this->fixture = new BrandingFixture();
        $this->branding = $this->fixture->service();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testANewSiteGetsGeneratedPictures(): void
    {
        self::assertSame('', $this->branding->state()->version);

        $state = $this->branding->regenerate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $state->version);
        self::assertSame(['file' => 'wordmark.svg', 'width' => 398, 'height' => 64, 'uploaded' => false], $state->asset(BrandAsset::Logo));
        self::assertSame(['file' => 'icon-512.png', 'width' => 512, 'height' => 512, 'uploaded' => false], $state->asset(BrandAsset::Icon));
        self::assertSame('share.png', $state->asset(BrandAsset::ShareImage)['file'] ?? null);
        self::assertEquals($state, $this->branding->state(), 'kept in the site settings');
        foreach (['wordmark.svg', 'icon.svg', 'favicon.ico', 'icon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'share.png'] as $file) {
            self::assertNotNull($this->branding->read($file), $file);
        }
        self::assertNull($this->branding->read('logo.svg'), 'not uploaded');
        self::assertNull($this->branding->read('../secrets'), 'only branding files');
    }

    public function testUploadsReplaceTheGeneratedPicturesUntilTheyAreReset(): void
    {
        $this->branding->regenerate();

        $state = $this->branding->set(BrandAsset::Logo, BrandingFixture::svg(), "  Riverside\n Library ");
        self::assertSame(['file' => 'logo.svg', 'width' => 300, 'height' => 100, 'uploaded' => true], $state->asset(BrandAsset::Logo));
        self::assertSame('Riverside Library', $state->alt(BrandAsset::Logo));
        self::assertNull($this->branding->read('wordmark.svg'), 'the generated wordmark is removed');

        $png = $this->branding->set(BrandAsset::Logo, MediaFixture::png(400, 100));
        self::assertSame(['file' => 'logo.png', 'width' => 400, 'height' => 100, 'uploaded' => true], $png->asset(BrandAsset::Logo));
        self::assertNull($png->alt(BrandAsset::Logo));
        self::assertNull($this->branding->read('logo.svg'));

        $icon = $this->branding->set(BrandAsset::Icon, MediaFixture::png(512, 512), 'ignored');
        self::assertTrue($icon->isUploaded(BrandAsset::Icon));
        self::assertNull($icon->alt(BrandAsset::Icon));
        self::assertNull($this->branding->read('icon.svg'), 'an uploaded icon has no SVG');
        $share = $this->branding->set(BrandAsset::ShareImage, MediaFixture::jpeg(1200, 630), 'The reading room');
        self::assertSame(['share.jpg', 'The reading room'], [$share->asset(BrandAsset::ShareImage)['file'] ?? null, $share->alt(BrandAsset::ShareImage)]);
        self::assertNull($this->branding->read('share.png'));
        self::assertNotSame($icon->version, $share->version, 'every change has a new version');

        $regenerated = $this->branding->regenerate();
        self::assertTrue($regenerated->isUploaded(BrandAsset::Logo), 'uploads stay');
        self::assertSame('The reading room', $regenerated->alt(BrandAsset::ShareImage));

        $reset = $this->branding->reset(BrandAsset::ShareImage);
        self::assertFalse($reset->isUploaded(BrandAsset::ShareImage));
        self::assertNull($reset->alt(BrandAsset::ShareImage));
        self::assertNotNull($this->branding->read('share.png'));
        self::assertNull($this->branding->read('share.jpg'));
    }

    public function testRefusedUploadsChangeNothing(): void
    {
        $before = $this->branding->regenerate();

        foreach ([[BrandAsset::Logo, BrandingFixture::svg(100, 300)], [BrandAsset::Logo, '<?xml version="1.0"?><!-- logo --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 90"><rect width="1" height="1"/></svg>'], [BrandAsset::Icon, MediaFixture::png(100, 100)]] as [$asset, $bytes]) {
            try {
                $this->branding->set($asset, $bytes);
                self::fail('Accepted a ' . $asset->label());
            } catch (BrandingException) {
                self::assertEquals($before, $this->branding->state());
            }
        }
    }

    public function testTheStateIsReadSafelyFromTheSettings(): void
    {
        self::assertEquals(new BrandingState(), BrandingState::fromJson(null));
        self::assertEquals(new BrandingState(), BrandingState::fromJson('not json'));
        $state = BrandingState::fromJson('{"assets":{"logo":{"file":"logo.svg","width":10,"height":5},"icon":{"file":3}},"alt":{"logo":"A","bad":4},"version":7}');
        self::assertSame(['logo' => ['file' => 'logo.svg', 'width' => 10, 'height' => 5, 'uploaded' => false]], $state->assets);
        self::assertSame(['logo' => 'A'], $state->alt);
        self::assertSame('', $state->version);
        self::assertEquals($state->withVersion('abc'), BrandingState::fromJson($state->withVersion('abc')->toJson()));
        self::assertNull($state->with(BrandAsset::Logo, null)->asset(BrandAsset::Logo));
        self::assertNull(BrandingState::fromJson('{"assets":[]}')->asset(BrandAsset::Icon));
    }
}
