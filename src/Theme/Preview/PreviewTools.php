<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

/**
 * Runs the browser tools on a static preview with Node.js and Playwright's Chromium: the screenshots
 * (`resources/preview/screenshot.mjs`: `screenshot.png`, `screenshot-dark.png`, `page.png`) and the axe scan
 * (`resources/preview/axe.mjs`: `axe.txt`). Only where those tools are installed (the dev-tools image, CI): neither is
 * ever a runtime dependency of core or of a theme.
 */
final readonly class PreviewTools
{
    public const array SCREENSHOTS = ['screenshot.png', 'screenshot-dark.png', 'page.png'];

    /**
     * @param string      $node    the Node.js program
     * @param string|null $scripts folder of the scripts (default: core's `resources/preview`)
     */
    public function __construct(private string $node = 'node', private ?string $scripts = null)
    {
    }

    /**
     * @return string|null what went wrong, in plain words; null when every screenshot was taken
     */
    public function screenshots(string $folder): ?string
    {
        [$status, $output] = $this->run('screenshot.mjs', $folder);

        return match ($status) {
            0 => null,
            127 => $this->missing('no screenshot was taken'),
            default => 'The screenshots could not be taken. ' . ($output === '' ? sprintf('The screenshot script stopped with code %d.', $status) : $output),
        };
    }

    /**
     * @return array{bool, string} whether axe found no problem (and no request left the folder), and its report
     */
    public function axe(string $folder): array
    {
        [$status, $output] = $this->run('axe.mjs', $folder);

        return match ($status) {
            0 => [true, $output],
            1 => [false, $output],
            127 => [false, $this->missing('the page was not scanned')],
            default => [false, 'The page could not be scanned. ' . ($output === '' ? sprintf('The axe script stopped with code %d.', $status) : $output)],
        };
    }

    /**
     * @return array{int, string}
     */
    private function run(string $script, string $folder): array
    {
        $script = ($this->scripts ?? dirname(__DIR__, 3) . '/resources/preview') . '/' . $script;
        $process = @proc_open([$this->node, $script, $folder], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = '';
        $status = is_resource($process) ? self::wait($process, $pipes, $output) : 127;

        return [$status, $output];
    }

    private function missing(string $what): string
    {
        return sprintf('Node.js (%s) is not installed or could not start, so %s. It needs Node.js and Playwright\'s Chromium, as in the dev-tools image.', $this->node, $what);
    }

    /**
     * @param resource             $process
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
