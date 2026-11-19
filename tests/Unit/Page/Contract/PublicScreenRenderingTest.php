<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Contract;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PublicScreenRendering;

#[CoversClass(PublicScreenRendering::class)]
final class PublicScreenRenderingTest extends TestCase
{
    public function testCollectsTemplateVariablesAndHeadTags(): void
    {
        $request = new ServerRequest('GET', '/search');
        $screen = new PublicScreenRendering($request, 'en-GB', '/search', 'https://example.org', 'Search');

        self::assertSame([$request, 'en-GB', '/search', 'https://example.org', 'Search'], [$screen->request, $screen->locale, $screen->url, $screen->baseUrl, $screen->title]);
        self::assertSame([], $screen->variables());
        self::assertSame('', $screen->head());
        $screen->set('main_menu', []);
        $screen->addHead('<link rel="icon" href="/favicon.ico">');
        $screen->addHead('<meta name="theme-color" content="#fff">');
        self::assertSame(['main_menu' => []], $screen->variables());
        self::assertSame("<link rel=\"icon\" href=\"/favicon.ico\">\n<meta name=\"theme-color\" content=\"#fff\">", $screen->head());
    }
}
