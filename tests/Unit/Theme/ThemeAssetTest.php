<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\ThemeAsset;

#[CoversClass(ThemeAsset::class)]
final class ThemeAssetTest extends TestCase
{
    public function testTheEtagChangesWithTheContents(): void
    {
        $a = new ThemeAsset('body {}', 'text/css; charset=utf-8');

        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $a->etag());
        self::assertSame($a->etag(), new ThemeAsset('body {}', 'text/css')->etag());
        self::assertNotSame($a->etag(), new ThemeAsset('main {}', 'text/css')->etag());
    }
}
