<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Branding;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-6.6 acceptance through the kernel: a site without uploads has its generated icons, wordmark and share image; the
 * `<head>` of its pages has icons, the manifest, Open Graph and Twitter tags with absolute, versioned addresses, and
 * the schema.org owner; uploads through `site:branding:set` are cleaned or refused; SVG files carry a strict CSP.
 */
#[CoversNothing]
final class BrandingTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('branding');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $now = new DateTimeImmutable();
        foreach (['Welcome', 'About us'] as $title) {
            $editor->create('en-GB', new PageContent($title, null, null, 'All about ' . $title . '.'))->changeState(PageState::Published, $now);
        }
        Services::get($container, PageRepository::class)->flush();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testASiteWithoutUploadsHasGeneratedIconsAndHeadTags(): void
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');
        $html = (string) $browser->request('GET', '/about-us')->getBody();

        self::assertSame(1, preg_match('#<meta property="og:image" content="(https://example\.org/branding/share\.png\?v=([0-9a-f]{12}))">#', $html, $image), $html);
        $version = $image[2];
        self::assertStringContainsString('<link rel="icon" href="https://example.org/favicon.ico?v=' . $version . '" sizes="32x32">', $html);
        self::assertStringContainsString('<link rel="icon" href="https://example.org/icon.svg?v=' . $version . '" type="image/svg+xml">', $html);
        self::assertStringContainsString('<link rel="manifest" href="/site.webmanifest">', $html);
        self::assertStringContainsString('<meta name="theme-color" content="#1f4e79">', $html);
        self::assertStringContainsString('<meta property="og:url" content="https://example.org/about-us">', $html);
        self::assertStringContainsString('<meta property="og:description" content="All about About us.">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        self::assertStringContainsString('<a class="site-name" href="/">Demo</a>', $html, 'no uploaded logo: the name stays text');
        self::assertSame(2, preg_match_all('#<script type="application/ld\+json">(.+?)</script>#', $html, $scripts), 'the owner and website here, the page from the SEO module');
        $json = implode("\n", $scripts[1]);
        self::assertStringContainsString('"@type":"Organization"', $json);
        self::assertStringContainsString('"logo":{"@type":"ImageObject","url":"https://example.org/branding/wordmark.svg?v=' . $version . '"', $json);

        $share = $browser->request('GET', '/branding/share.png?v=' . $version);
        self::assertSame('image/png', $share->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $share->getHeaderLine('Cache-Control'));
        self::assertSame([1200, 630], array_slice((array) getimagesizefromstring((string) $share->getBody()), 0, 2));

        $favicon = $browser->request('GET', '/favicon.ico');
        self::assertSame('image/x-icon', $favicon->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=3600', $favicon->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith("\x00\x00\x01\x00\x01\x00", (string) $favicon->getBody());
        self::assertSame(304, $browser->request('GET', '/favicon.ico', headers: ['If-None-Match' => $favicon->getHeaderLine('ETag')])->getStatusCode());
        foreach (['/apple-touch-icon.png' => 180, '/icon-192.png' => 192, '/icon-512.png' => 512] as $path => $pixels) {
            self::assertSame([$pixels, $pixels], array_slice((array) getimagesizefromstring((string) $browser->request('GET', $path)->getBody()), 0, 2), $path);
        }
        $svg = $browser->request('GET', '/icon.svg');
        self::assertSame('image/svg+xml', $svg->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $svg->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('>D</text>', (string) $svg->getBody());
        self::assertSame(404, $browser->request('GET', '/branding/logo.svg')->getStatusCode());
        self::assertSame(404, $browser->request('GET', '/branding/..%2Fconfig')->getStatusCode());

        $manifest = json_decode((string) $browser->request('GET', '/site.webmanifest')->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(['Demo', 'Demo', '/', '#1f4e79'], [$manifest['name'], $manifest['short_name'], $manifest['start_url'], $manifest['theme_color']]);
        self::assertSame(['192x192', '512x512', 'any'], array_column((array) $manifest['icons'], 'sizes'));
    }

    public function testUploadsAreCheckedCleanedAndShown(): void
    {
        $directory = $this->site->project->directory;
        $evil = $directory->write('upload/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 300 100" onload="alert(1)">'
            . '<script>alert(2)</script><rect width="300" height="100" fill="#123456"/><image xlink:href="https://evil.example/x.png" width="10" height="10"/>'
            . '<a href="javascript:alert(3)"><text x="10" y="60">Library</text></a></svg>');
        $small = $directory->write('upload/small.png', MediaFixture::png(300, 200));

        [$status, $display] = $this->console(['command' => 'site:branding:set', 'slug' => 'demo', '--logo' => $evil, '--logo-alt' => 'Riverside Library']);
        self::assertSame(0, $status, $display);
        self::assertStringContainsString('The logo has been changed.', $display);
        [$status, $display] = $this->console(['command' => 'site:branding:set', 'slug' => 'demo', '--icon' => $small]);
        self::assertSame(1, $status);
        self::assertStringContainsString('The icon must be a square picture of at least 512 × 512 pixels. This one is 300 × 200 pixels.', $display);
        [$status, $display] = $this->console(['command' => 'site:branding:set', 'slug' => 'demo', '--share-image' => $small]);
        self::assertStringContainsString('The share image must be 1200 × 630 pixels, or larger in the same proportions. This one is 300 × 200 pixels.', $display);

        $browser = new Browser($this->site->kernel, 'https://example.org');
        $html = (string) $browser->request('GET', '/about-us')->getBody();
        self::assertMatchesRegularExpression('#<a class="site-name site-name--logo" href="/"><img src="https://example\.org/branding/logo\.svg\?v=[0-9a-f]{12}" alt="Riverside Library" width="300" height="100"></a>#', $html);
        $logo = (string) $browser->request('GET', '/branding/logo.svg')->getBody();
        foreach (['script', 'onload', 'alert', 'evil.example', 'javascript:'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $logo);
        }
        self::assertStringContainsString('<rect', $logo);

        [$status] = $this->console(['command' => 'site:branding:reset', 'slug' => 'demo', '--logo' => true]);
        self::assertSame(0, $status);
        self::assertStringContainsString('<a class="site-name" href="/">Demo</a>', (string) $browser->request('GET', '/about-us')->getBody());
        self::assertSame(1, $this->console(['command' => 'site:branding:regenerate', 'slug' => 'nowhere'])[0]);
    }

    public function testAPageCanShareItsOwnPicture(): void
    {
        $container = $this->site->container();
        $picture = Services::get($container, MediaUploader::class)->upload($this->site->project->directory->write('upload/harbour.jpg', MediaFixture::jpeg(1200, 630)), 'Harbour.jpg', null)->media;
        $picture->describe('en-GB', 'Boats in the harbour', null, new DateTimeImmutable());
        $picture->publish(new DateTimeImmutable());
        Services::get($container, MediaRepository::class)->save($picture);
        $pages = Services::get($container, PageRepository::class);
        $about = $pages->findByPath('en-GB', 'about-us');
        self::assertNotNull($about);
        Services::get($container, PageEditor::class)->save($about->page(), 'en-GB', new PageContent('About us', 'about-us', null, null, [], (int) $picture->id()));

        $html = (string) new Browser($this->site->kernel, 'https://example.org')->request('GET', '/about-us')->getBody();

        self::assertMatchesRegularExpression('#<meta property="og:image" content="https://example\.org/media/[0-9a-f]{32}/harbour\.jpg">#', $html);
        self::assertStringContainsString('<meta property="og:image:alt" content="Boats in the harbour">', $html);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function console(array $input): array
    {
        $application = $this->site->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }
}
