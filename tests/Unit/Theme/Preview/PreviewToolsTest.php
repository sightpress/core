<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Preview\PreviewTools;

#[CoversClass(PreviewTools::class)]
final class PreviewToolsTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testRunsTheScreenshotScriptOnTheFolder(): void
    {
        $node = $this->node('echo "$1 $2" > "$2/ran"; exit 0');

        self::assertNull(new PreviewTools($node)->screenshots($this->directory->path));
        self::assertSame(dirname(__DIR__, 4) . '/resources/preview/screenshot.mjs ' . $this->directory->path . "\n", file_get_contents($this->directory->path . '/ran'));
    }

    public function testSaysWhenNodeIsMissing(): void
    {
        $node = $this->directory->path . '/no-node';

        self::assertSame(
            'Node.js (' . $node . ') is not installed or could not start, so no screenshot was taken. It needs Node.js and Playwright\'s Chromium, as in the dev-tools image.',
            new PreviewTools($node)->screenshots('.'),
        );
        self::assertSame(
            [false, 'Node.js (' . $node . ') is not installed or could not start, so the page was not scanned. It needs Node.js and Playwright\'s Chromium, as in the dev-tools image.'],
            new PreviewTools($node)->axe('.'),
        );
    }

    public function testPassesOnWhatTheScreenshotScriptSays(): void
    {
        $node = $this->node('echo "Chromium could not start: no display" >&2; exit 3');

        self::assertSame('The screenshots could not be taken. Chromium could not start: no display', new PreviewTools($node, '/scripts')->screenshots('.'));
        self::assertSame('The screenshots could not be taken. The screenshot script stopped with code 4.', new PreviewTools($this->node('exit 4'))->screenshots('.'));
    }

    public function testReportsWhatAxeFound(): void
    {
        self::assertSame([true, 'light at 1280 px: no violations'], new PreviewTools($this->node('echo "$1" | grep -q axe.mjs && echo "light at 1280 px: no violations"'))->axe('.'));
        self::assertSame([false, 'dark at 320 px: 1 violation'], new PreviewTools($this->node('echo "dark at 320 px: 1 violation"; exit 1'))->axe('.'));
        self::assertSame([false, 'The page could not be scanned. Chromium could not start'], new PreviewTools($this->node('echo "Chromium could not start" >&2; exit 3'))->axe('.'));
        self::assertSame([false, 'The page could not be scanned. The axe script stopped with code 2.'], new PreviewTools($this->node('exit 2'))->axe('.'));
    }

    private function node(string $script): string
    {
        $file = $this->directory->write('node-' . md5($script), "#!/bin/sh\n" . $script . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
