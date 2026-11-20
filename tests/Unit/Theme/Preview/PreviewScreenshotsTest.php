<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Preview\PreviewScreenshots;

#[CoversClass(PreviewScreenshots::class)]
final class PreviewScreenshotsTest extends TestCase
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

    public function testRunsTheScriptOnTheFolder(): void
    {
        $node = $this->node('echo "$1 $2" > "$2/ran"; exit 0');

        self::assertNull(new PreviewScreenshots($node)->take($this->directory->path));
        self::assertSame(dirname(__DIR__, 4) . '/resources/preview/screenshot.mjs ' . $this->directory->path . "\n", file_get_contents($this->directory->path . '/ran'));
    }

    public function testSaysWhenNodeIsMissing(): void
    {
        $problem = new PreviewScreenshots($this->directory->path . '/no-node')->take($this->directory->path);

        self::assertSame(
            'Node.js (' . $this->directory->path . '/no-node) is not installed or could not start, so no screenshot was taken. Screenshots need Node.js and Playwright\'s Chromium, as in the dev-tools image.',
            $problem,
        );
    }

    public function testPassesOnWhatTheScriptSays(): void
    {
        $node = $this->node('echo "Chromium could not start: no display" >&2; exit 3');

        self::assertSame('The screenshots could not be taken. Chromium could not start: no display', new PreviewScreenshots($node, 'script.mjs')->take('.'));
    }

    public function testNamesTheExitCodeWhenTheScriptSaysNothing(): void
    {
        self::assertSame('The screenshots could not be taken. The screenshot script stopped with code 4.', new PreviewScreenshots($this->node('exit 4'))->take('.'));
    }

    private function node(string $script): string
    {
        $file = $this->directory->write('node', "#!/bin/sh\n" . $script . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
