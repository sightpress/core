<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

use SightPress\Core\Theme\ThemeException;

/**
 * The folder a preview is written to. It must be empty, or hold an earlier preview (an `index.html`), which is then
 * replaced as a whole, so nothing of an older run is left behind and nothing else is ever deleted.
 */
final readonly class PreviewFolder
{
    /**
     * @param non-empty-string $path
     */
    public function __construct(public string $path)
    {
    }

    /**
     * @param array<string, string> $files contents by site address ({@see StaticPage::fileName()})
     *
     * @throws ThemeException when the folder holds something else, or can't be written
     */
    public function write(array $files): void
    {
        if (is_dir($this->path) && !is_file($this->path . '/index.html') && self::entries($this->path) !== []) {
            throw new ThemeException(sprintf('The folder %s is not empty and holds no earlier preview: choose another one with --out.', $this->path));
        }
        self::remove($this->path);
        foreach ($files as $address => $contents) {
            $file = $this->path . '/' . StaticPage::fileName($address);
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o777, true) || @file_put_contents($file, $contents) === false) {
                throw new ThemeException(sprintf('The preview could not be written to %s.', $file));
            }
        }
    }

    /**
     * Deletes a folder and everything in it; nothing happens when it isn't there.
     */
    public static function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach (self::entries($path) as $entry) {
            $entry = $path . '/' . $entry;
            is_dir($entry) && !is_link($entry) ? self::remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }

    /**
     * @return list<string>
     */
    private static function entries(string $path): array
    {
        $entries = scandir($path);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
