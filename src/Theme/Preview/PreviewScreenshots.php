<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

/**
 * Takes the screenshots of a static preview (`screenshot.png`, `screenshot-dark.png`, `page.png`) with Playwright's
 * Chromium, through Node.js and core's `resources/preview/screenshot.mjs`. Only where those tools are installed (the
 * dev-tools image, CI): neither is ever a runtime dependency of core or of a theme.
 */
final readonly class PreviewScreenshots
{
    public const array FILES = ['screenshot.png', 'screenshot-dark.png', 'page.png'];

    /**
     * @param string      $node   the Node.js program
     * @param string|null $script the screenshot script (default: core's)
     */
    public function __construct(private string $node = 'node', private ?string $script = null)
    {
    }

    /**
     * @return string|null what went wrong, in plain words; null when every screenshot was taken
     */
    public function take(string $folder): ?string
    {
        $script = $this->script ?? dirname(__DIR__, 3) . '/resources/preview/screenshot.mjs';
        $process = @proc_open([$this->node, $script, $folder], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = '';
        $status = is_resource($process) ? self::wait($process, $pipes, $output) : 127;
        if ($status === 0) {
            return null;
        }
        if ($status === 127) {
            return sprintf('Node.js (%s) is not installed or could not start, so no screenshot was taken. Screenshots need Node.js and Playwright\'s Chromium, as in the dev-tools image.', $this->node);
        }

        return 'The screenshots could not be taken. ' . ($output === '' ? sprintf('The screenshot script stopped with code %d.', $status) : $output);
    }

    /**
     * @param resource              $process
     * @param array<int, resource> $pipes
     */
    private static function wait($process, array $pipes, string &$output): int
    {
        $output = trim(stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
