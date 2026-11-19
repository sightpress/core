<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Contract;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Contract\AnsweredResource;

#[CoversClass(AnsweredResource::class)]
final class AnsweredResourceTest extends TestCase
{
    public function testSaysWhatTheResponseShowed(): void
    {
        $resource = new AnsweredResource();
        self::assertSame([null, null, null], [$resource->kind(), $resource->pageId(), $resource->locale()]);

        $resource->page(4, 'pt-PT');
        self::assertSame(['page', 4, 'pt-PT'], [$resource->kind(), $resource->pageId(), $resource->locale()]);
        $resource->page(4, 'pt-PT', markdown: true);
        self::assertSame('markdown', $resource->kind());
        $resource->file('sitemap');
        self::assertSame(['sitemap', null, null], [$resource->kind(), $resource->pageId(), $resource->locale()]);

        self::assertNull(AnsweredResource::fromRequest(new ServerRequest('GET', '/')));
        self::assertSame($resource, AnsweredResource::fromRequest(new ServerRequest('GET', '/')->withAttribute(AnsweredResource::class, $resource)));
    }
}
