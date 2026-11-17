<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandingHead;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\BrandingFixture;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(BrandingHead::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingHeadTest extends TestCase
{
    private ?BrandingFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->remove();
    }

    public function testAddsIconsSharingTagsAndTheOwnerToEveryPage(): void
    {
        $listener = $this->listener();

        $event = $this->event(home: false, description: 'Opening hours & events <today>.');
        $listener($event);
        $head = $event->head();

        $v = '?v=' . $this->fixture?->service()->state()->version;
        self::assertNotSame('?v=', $v, 'the generated pictures were made on the first page');
        self::assertStringContainsString('<link rel="icon" href="https://demo.example/favicon.ico' . $v . '" sizes="32x32">', $head);
        self::assertStringContainsString('<link rel="icon" href="https://demo.example/icon.svg' . $v . '" type="image/svg+xml">', $head);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="https://demo.example/apple-touch-icon.png' . $v . '">', $head);
        self::assertStringContainsString('<meta property="og:type" content="article">', $head);
        self::assertStringContainsString('<meta property="og:description" content="Opening hours &amp; events &lt;today&gt;.">', $head);
        self::assertStringContainsString('<meta property="og:locale" content="en_GB">', $head);
        self::assertStringContainsString('<meta property="og:image" content="https://demo.example/branding/share.png' . $v . '">', $head);
        self::assertStringContainsString('<meta property="og:image:alt" content="Riverside Library">', $head);
        self::assertStringContainsString('<meta name="twitter:image" content="https://demo.example/branding/share.png' . $v . '">', $head);
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.+)</script>#', $head, $json));
        self::assertStringNotContainsString('<', $json[1], 'nothing in the JSON can close the script');
        self::assertSame([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => 'https://demo.example/#organization', 'name' => 'Riverside Library', 'url' => 'https://demo.example/', 'logo' => [
                    '@type' => 'ImageObject', 'url' => 'https://demo.example/branding/wordmark.svg' . $v, 'width' => 398, 'height' => 64,
                ]],
                ['@type' => 'WebSite', '@id' => 'https://demo.example/#website', 'name' => 'Riverside Library', 'url' => 'https://demo.example/', 'inLanguage' => 'en-GB', 'publisher' => ['@id' => 'https://demo.example/#organization']],
            ],
        ], json_decode($json[1], true));
        self::assertArrayNotHasKey('branding_logo', $event->variables());
    }

    public function testUploadedPicturesAndAPagesOwnShareImageAreUsed(): void
    {
        $listener = $this->listener(OwnerType::Person, regenerate: true);
        $branding = $this->fixture?->service();
        self::assertNotNull($branding);
        $branding->set(BrandAsset::Logo, BrandingFixture::svg(), 'Ada’s workshop');
        $branding->set(BrandAsset::Icon, MediaFixture::png(512, 512));
        $branding->set(BrandAsset::ShareImage, MediaFixture::jpeg(1200, 630), 'The workshop');
        $settings = $this->fixture?->database->settings();
        self::assertNotNull($settings);
        foreach ([
            SiteProfile::DESCRIPTION => 'Ada makes furniture.', SiteProfile::EMAIL => 'ada@example.org', SiteProfile::PHONE => '+44 20 7946 0000',
            SiteProfile::ADDRESS => '1 Mill Lane, Leeds', SiteProfile::LEGAL_NAME => 'Ignored for a person', SiteProfile::SAME_AS => "https://example.social/@ada\nnot a link",
        ] as $key => $value) {
            $settings->set($key, $value);
        }
        $blocks = new BlockFixture([], $this->fixture?->database);
        $picture = $blocks->addMedia();
        $draft = $blocks->addMedia(published: false);
        $video = $blocks->addMedia(kind: MediaKind::Video, fileName: 'tour.mp4');
        $v = '?v=' . $branding->state()->version;

        $home = $this->event(home: true, description: null, socialImage: $draft);
        $listener($home);
        self::assertStringNotContainsString('icon.svg', $home->head(), 'an uploaded icon has no SVG');
        self::assertStringContainsString('<meta property="og:type" content="website">', $home->head());
        self::assertStringNotContainsString('og:description', $home->head());
        self::assertStringContainsString('<meta property="og:image" content="https://demo.example/branding/share.jpg' . $v . '">', $home->head(), 'an unpublished picture is not shared');
        self::assertStringContainsString('<meta property="og:image:alt" content="The workshop">', $home->head());
        self::assertStringContainsString('"@type":"Person","@id":"https://demo.example/#person","name":"Riverside Library","url":"https://demo.example/","description":"Ada makes furniture.","email":"ada@example.org","telephone":"+44 20 7946 0000","address":"1 Mill Lane, Leeds","sameAs":["https://example.social/@ada"]', $home->head());
        self::assertStringContainsString('"image":{"@type":"ImageObject","url":"https://demo.example/branding/logo.svg' . $v . '"', $home->head());
        self::assertSame(['url' => 'https://demo.example/branding/logo.svg' . $v, 'alt' => 'Ada’s workshop', 'width' => 300, 'height' => 100], $home->variables()['branding_logo']);

        $own = $this->event(home: false, description: null, socialImage: $picture);
        $listener($own);
        self::assertStringContainsString('<meta property="og:image" content="https://demo.example/media/' . $blocks->key($picture) . '/lighthouse.jpg">', $own->head());
        self::assertStringContainsString('<meta property="og:image:width" content="1280">', $own->head());
        self::assertStringContainsString('<meta property="og:image:alt" content="Opening hours">', $own->head(), 'no alt text: the page title');

        $notAPicture = $this->event(home: false, description: null, socialImage: $video);
        $listener($notAPicture);
        self::assertStringContainsString('share.jpg', $notAPicture->head());

        $branding->reset(BrandAsset::Logo);
        $plain = $this->event(home: false, description: null);
        $listener($plain);
        self::assertArrayNotHasKey('branding_logo', $plain->variables());
    }

    private function listener(OwnerType $owner = OwnerType::Organization, bool $regenerate = false): BrandingHead
    {
        $this->fixture = new BrandingFixture('Riverside Library', $owner);
        if ($regenerate) {
            $this->fixture->service()->regenerate();
        }
        $blocks = new BlockFixture([], $this->fixture->database);

        return new BrandingHead($this->fixture->service(), $this->fixture->site, $blocks->media(), new OriginMediaUrlGenerator(), $this->fixture->database->settings());
    }

    private function event(bool $home, ?string $description, ?int $socialImage = null): PublicPageRendering
    {
        $request = new ServerRequest('GET', 'https://demo.example/hours')->withAttribute(CspNonce::class, new CspNonce('n0nce'));

        return new PublicPageRendering($request, 3, 'en-GB', $home ? '/' : '/hours', 'https://demo.example/', 'Opening hours', null, $description, [], $home, true, new DateTimeImmutable(), $socialImage);
    }
}
