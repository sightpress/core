<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Error\ErrorPage;
use SightPress\Core\Http\Error\HtmlErrorRenderer;
use SightPress\Core\Http\Error\JsonErrorRenderer;

#[CoversClass(HtmlErrorRenderer::class)]
#[CoversClass(JsonErrorRenderer::class)]
#[UsesClass(ErrorPage::class)]
final class RenderersTest extends TestCase
{
    public function testHtmlPageIsAccessibleAndEscaped(): void
    {
        $page = new ErrorPage(404, 'Page <not> found', ['Check "the" address.']);

        $html = new HtmlErrorRenderer()->render($page);

        self::assertStringContainsString('<html lang="en-GB">', $html);
        self::assertStringContainsString('<title>Page &lt;not&gt; found - SightPress</title>', $html);
        self::assertSame(1, substr_count($html, '<main>'));
        self::assertSame(1, substr_count($html, '<h1>'));
        self::assertStringContainsString('<h1>Page &lt;not&gt; found</h1>', $html);
        self::assertStringContainsString('<p>Check &quot;the&quot; address.</p>', $html);
        self::assertStringContainsString('<p>Error code: 404</p>', $html);
        self::assertStringContainsString('<a href="/">Go to the home page</a>', $html);
        self::assertStringNotContainsString('<details>', $html);
    }

    public function testHtmlPageCanLeaveOutTheHomeLinkAndShowDetails(): void
    {
        $page = new ErrorPage(500, 'Oops', ['Try later.'], homeLink: false);

        $html = new HtmlErrorRenderer('Example')->render($page, 'RuntimeException: <boom>');

        self::assertStringContainsString('<title>Oops - Example</title>', $html);
        self::assertStringNotContainsString('href="/"', $html);
        self::assertStringContainsString('<summary>Technical details</summary>', $html);
        self::assertStringContainsString('RuntimeException: &lt;boom&gt;', $html);
    }

    public function testThePolicyAllowsOnlyThePageStyle(): void
    {
        $renderer = new HtmlErrorRenderer();
        $html = $renderer->render(new ErrorPage(404, 'Page not found', ['x']));
        self::assertSame(1, preg_match('#<style>(.*)</style>#s', $html, $match));

        $hash = 'sha256-' . base64_encode(hash('sha256', $match[1], true));

        self::assertSame(
            "default-src 'none'; style-src '{$hash}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            $renderer->contentSecurityPolicy(),
        );
    }

    public function testJsonIsProblemDetails(): void
    {
        $page = new ErrorPage(404, 'Page not found', ['First.', 'Second.']);
        $renderer = new JsonErrorRenderer();

        self::assertSame(
            '{"type":"about:blank","title":"Page not found","status":404,"detail":"First. Second."}',
            $renderer->render($page),
        );
        self::assertSame(
            '{"type":"about:blank","title":"Page not found","status":404,"detail":"First. Second.","debug":"trace/é"}',
            $renderer->render($page, 'trace/é'),
        );
    }
}
