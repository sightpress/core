<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandingException;
use SightPress\Core\Branding\SvgCleaner;

#[CoversClass(SvgCleaner::class)]
#[UsesClass(BrandingException::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class SvgCleanerTest extends TestCase
{
    public function testRemovesScriptsHandlersAndOutsideReferences(): void
    {
        [$svg, $width, $height] = SvgCleaner::clean('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 240 80" onload="alert(1)">'
            . '<script>alert(2)</script><foreignObject><p>x</p></foreignObject><use xlink:href="https://evil.example/s.svg#a"/>'
            . '<circle cx="40" cy="40" r="30" fill="#123456" onclick="alert(3)" style="fill:url(https://evil.example/x)"/></svg>');

        self::assertSame([240.0, 80.0], [$width, $height]);
        foreach (['script', 'onload', 'onclick', 'alert', 'evil.example', 'foreignObject', '<?xml'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $svg);
        }
        self::assertStringContainsString('<circle', $svg);
    }

    public function testTakesTheSizeFromWidthAndHeightWithoutAViewBox(): void
    {
        self::assertSame([120.0, 40.0], array_slice(SvgCleaner::clean('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40"><rect width="120" height="40"/></svg>'), 1));
    }

    public function testRefusesFilesThatCantBeUsed(): void
    {
        foreach ([
            str_repeat(' ', SvgCleaner::MAX_BYTES + 1) => 'An SVG logo must be 512 KB or smaller.',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect' => 'This SVG file can\'t be read.',
            '' => 'This SVG file can\'t be read.',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script></svg>' => 'This SVG file has nothing left to show once it is made safe.',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 10"><rect width="1" height="1"/></svg>' => 'This SVG file has no size',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>' => 'This SVG file has no size',
        ] as $svg => $message) {
            try {
                SvgCleaner::clean($svg);
                self::fail('Accepted: ' . $message);
            } catch (BrandingException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
        }
    }
}
