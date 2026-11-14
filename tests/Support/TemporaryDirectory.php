<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

/**
 * A folder under the system temp dir that is deleted again after the test.
 */
final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/sightpress-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0o777, true);
    }

    public function write(string $relativePath, string $contents): string
    {
        $file = $this->path . '/' . $relativePath;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $contents);

        return $file;
    }

    public function remove(): void
    {
        self::delete($this->path);
    }

    private static function delete(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) === false ? [] : scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::delete($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
