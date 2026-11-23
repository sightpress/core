<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\DeliveryMediaUrlGenerator;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(DeliveryMediaUrlGenerator::class)]
#[CoversClass(OriginMediaUrlGenerator::class)]
#[UsesNamespace('SightPress\Core')]
final class DeliveryMediaUrlGeneratorTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    private const string CDN = 'https://cdn.example';

    private ArraySiteSettings $settings;

    protected function setUp(): void
    {
        $this->settings = new ArraySiteSettings();
    }

    private function media(bool $published = true, string $checksum = ''): Media
    {
        $media = new Media(self::KEY, $checksum === '' ? str_repeat('a', 64) : $checksum, new MediaFormat('image/jpeg', MediaKind::Image, 'jpg', 'JPEG'), 'photo.jpg', 'Photo.jpg', 100, 1000, 500, [320, 640], 1, new DateTimeImmutable('2026-11-14T12:00:00Z'));
        if ($published) {
            $media->publish(new DateTimeImmutable('2026-11-14T12:00:00Z'));
        }

        return $media;
    }

    private function urls(?string $cdn = self::CDN, ?string $secret = SiteFixture::SECRET): DeliveryMediaUrlGenerator
    {
        $site = SiteFixture::site();

        return new DeliveryMediaUrlGenerator($this->withCdn($site, $cdn), $this->settings, new MediaUrlSigner($secret, $site->id, new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z'))));
    }

    private function withCdn(Site $site, ?string $cdn): Site
    {
        return $site->withStorage($site->storage->withCdn($cdn));
    }

    public function testWithoutACdnNothingChanges(): void
    {
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls(null)->url($this->media()));
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls(null)->temporaryUrl($this->media(false)));
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls(null)->url($this->media()));
    }

    public function testPublicFilesUseTheCdnWithTheirChecksum(): void
    {
        self::assertSame(self::CDN . '/media/' . self::KEY . '/640w/photo.jpg?v=' . str_repeat('a', 16), $this->urls()->url($this->media(), 640));
        self::assertNotSame($this->urls()->url($this->media()), $this->urls()->url($this->media(checksum: str_repeat('b', 64))));
    }

    public function testAnUnpublishedFileIsNeverOnTheCdnOrSignedInAPage(): void
    {
        $url = $this->urls()->url($this->media(false));

        self::assertSame('/media/' . self::KEY . '/photo.jpg', $url);
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls()->url($this->media(false)));
    }

    public function testEveryFileOfAMembersOnlySiteIsSigned(): void
    {
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        $url = $this->urls()->url($this->media(), 640);

        self::assertStringStartsWith(self::CDN . '/media/' . self::KEY . '/640w/photo.jpg?expires=', $url);
        self::assertStringContainsString('&signature=', $url);
        foreach (explode(', ', $this->urls()->srcset($this->media())) as $candidate) {
            self::assertStringContainsString('signature=', $candidate);
        }
    }

    public function testASignatureMatchesTheWidthReallyServed(): void
    {
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        $site = SiteFixture::site();
        $signer = new MediaUrlSigner(SiteFixture::SECRET, $site->id, new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z')));
        parse_str((string) parse_url($this->urls()->url($this->media(), 999), PHP_URL_QUERY), $query);

        self::assertTrue($signer->verifies(self::KEY, null, $query));
    }

    public function testWithoutASecretAddressesStayOnTheSite(): void
    {
        $this->settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls(secret: null)->url($this->media()));
        self::assertSame('/media/' . self::KEY . '/photo.jpg', $this->urls(secret: null)->temporaryUrl($this->media(false)));
    }

    public function testATemporaryAddressIsSignedAndOnTheSite(): void
    {
        $url = $this->urls()->temporaryUrl($this->media(false), 320);

        self::assertStringStartsWith('/media/' . self::KEY . '/320w/photo.jpg?expires=', $url);
    }

    public function testSrcsetListsEveryWidthAndNothingForOtherFiles(): void
    {
        self::assertStringContainsString('320w, ', $this->urls()->srcset($this->media()));
        self::assertStringEndsWith(' 1000w', $this->urls()->srcset($this->media()));
        $file = new Media(self::KEY, str_repeat('a', 64), new MediaFormat('text/plain', MediaKind::Document, 'txt', 'Text'), 'a.txt', 'a.txt', 1, null, null, [], 1, new DateTimeImmutable('2026-11-14T12:00:00Z'));
        self::assertSame('', $this->urls()->srcset($file));
    }
}
