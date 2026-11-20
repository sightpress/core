<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\Preview\StaticPage;

#[CoversClass(StaticPage::class)]
final class StaticPageTest extends TestCase
{
    private const string PAGE = <<<'HTML'
        <link rel="stylesheet" href="/assets/css/site.css?v=1">
        <link rel="canonical" href="https://site.test/">
        <link rel="alternate" type="text/markdown" href="https://site.test/index.md">
        <link rel="icon" href="https://site.test/favicon.ico?v=0123456789ab">
        <link rel="manifest" href="/site.webmanifest">
        <a class="site-name" href="/">Site</a>
        <a href="/#text">Text</a> <a href="/about">About</a> <a href="https://elsewhere.test/">Away</a> <a href="#content">Skip</a>
        <img src="https://site.test/media/00112233445566778899aabbccddeeff/a.jpg" srcset="/media/00112233445566778899aabbccddeeff/320w/a.jpg 320w, /media/00112233445566778899aabbccddeeff/a.jpg 1200w, ">
        <a href="https://site.test/media/ffeeddccbbaa99887766554433221100/hours.pdf" download>Hours</a>
        <video poster="//cdn.test/p.jpg"><source src="/media/x%20y/v.mp4"></video>
        <form method="get" action="/search"><input name="q"></form>
        <form id="c" action="/contact" method="post"><input type="hidden" name="csrf_name" value="abc"><input type="hidden" name="csrf_value" value="def"><button>Send</button></form>
        <script nonce="r4nd0m">1</script>
        <h2 class="block-heading">Text &amp; more</h2><h2 class="block-heading">Gone</h2>
        HTML;

    public function testListsTheSiteFilesThePageLoads(): void
    {
        self::assertSame([
            '/assets/css/site.css',
            '/favicon.ico',
            '/site.webmanifest',
            '/media/00112233445566778899aabbccddeeff/a.jpg',
            '/media/00112233445566778899aabbccddeeff/320w/a.jpg',
            '/media/ffeeddccbbaa99887766554433221100/hours.pdf',
            '/media/x y/v.mp4',
        ], new StaticPage('https://site.test')->files(self::PAGE));
    }

    public function testMakesThePageStatic(): void
    {
        $html = new StaticPage('https://site.test')->rewrite(self::PAGE, [
            '/assets/css/site.css',
            '/favicon.ico',
            '/media/00112233445566778899aabbccddeeff/a.jpg',
            '/media/00112233445566778899aabbccddeeff/320w/a.jpg',
            '/media/ffeeddccbbaa99887766554433221100/hours.pdf',
        ], [['id' => 'text', 'heading' => 'Text & more'], ['id' => 'missing', 'heading' => 'Not there']]);

        self::assertStringContainsString('href="assets/css/site.css"', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://site.test/">', $html);
        self::assertStringContainsString('href="https://site.test/index.md"', $html);
        self::assertStringContainsString('<link rel="icon" href="favicon.ico">', $html);
        self::assertStringNotContainsString('manifest', $html);
        self::assertStringContainsString('<a class="site-name" href="index.html">', $html);
        self::assertStringContainsString('<a href="#text">Text</a> <a href="#">About</a> <a href="https://elsewhere.test/">Away</a> <a href="#content">', $html);
        self::assertStringContainsString('srcset="media/00112233445566778899aabbccddeeff/320w/a.jpg 320w, media/00112233445566778899aabbccddeeff/a.jpg 1200w, "', $html);
        self::assertStringContainsString('href="media/ffeeddccbbaa99887766554433221100/hours.pdf" download', $html);
        self::assertStringContainsString('poster="//cdn.test/p.jpg"><source src="#">', $html);
        self::assertStringContainsString('<form method="get" action="#"><input name="q"></form>', $html);
        self::assertStringContainsString('name="csrf_name" value="preview"><input type="hidden" name="csrf_value" value="preview"><button>Send</button><p class="form-note">This is a preview: the form doesn&apos;t send anything.</p></form>', $html);
        self::assertStringContainsString('<script nonce="preview">', $html);
        self::assertStringContainsString('<h2 class="block-heading" id="text">Text &amp; more</h2><h2 class="block-heading">Gone</h2>', $html);
    }

    public function testFollowsAndRewritesTheFilesOfAStylesheet(): void
    {
        $css = '@font-face{src:url("../fonts/a.woff2?v=2")} .i{background:url(/assets/i.svg#x)} .d{background:url(data:image/png;base64,AA)} .r{background:url(https://cdn.test/r.png)} .f{mask:url(#m)} .e{x:url( )}';
        $page = new StaticPage();

        self::assertSame(['/assets/fonts/a.woff2', '/assets/i.svg'], $page->cssFiles($css, '/assets/css/site.css'));
        self::assertSame(
            '@font-face{src:url("../../assets/fonts/a.woff2")} .i{background:url(../../assets/i.svg)} .d{background:url(data:image/png;base64,AA)} .r{background:url(https://cdn.test/r.png)} .f{mask:url(#m)} .e{x:url( )}',
            $page->rewriteCss($css, '/assets/css/site.css'),
        );
    }

    public function testNamesFiles(): void
    {
        self::assertSame('index.html', StaticPage::fileName('/'));
        self::assertSame('assets/css/site.css', StaticPage::fileName('/assets/css/site.css'));
    }

    public function testReplacesWhatTheSitePicksAtRandom(): void
    {
        $stable = new StaticPage()->stabilise([
            '/' => '<img src="media/00112233445566778899aabbccddeeff/a.jpg"><link href="favicon.ico?v=0123456789ab">',
            '/media/00112233445566778899aabbccddeeff/a.jpg' => 'picture',
            '/media/00112233445566778899aabbccddeeff/320w/a.jpg' => 'small picture',
            '/assets/site.css' => '.x{background:url(../media/00112233445566778899aabbccddeeff/a.jpg)}',
            '/media/ffeeddccbbaa99887766554433221100/a.jpg' => 'media/00112233445566778899aabbccddeeff stays in a binary file',
        ]);
        $name = substr(hash('sha256', 'picture'), 0, 32);

        self::assertSame([
            '/' => '<img src="media/' . $name . '/a.jpg"><link href="favicon.ico?v=preview">',
            '/media/' . $name . '/a.jpg' => 'picture',
            '/media/' . $name . '/320w/a.jpg' => 'small picture',
            '/assets/site.css' => '.x{background:url(../media/' . $name . '/a.jpg)}',
            '/media/' . substr(hash('sha256', 'media/00112233445566778899aabbccddeeff stays in a binary file'), 0, 32) . '/a.jpg' => 'media/00112233445566778899aabbccddeeff stays in a binary file',
        ], $stable);
    }
}
