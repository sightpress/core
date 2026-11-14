<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * The file behind the site registry (ADR-0002). Reading is a plain `require`, so OPcache compiles it once.
 *
 * Writing is for CLI commands only: under an exclusive lock it reads the current file, builds the new array,
 * exports it with `var_export()`, keeps the old file as `<file>.bak`, writes a temporary file in the same folder
 * and renames it over the old one, so readers never see half a file.
 */
final readonly class SiteRegistryFile
{
    private const string HEADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        /*
         * Site registry: which sites this install runs, their domains, status and (encrypted) database DSN.
         *
         * Written by `bin/sightpress site:*` commands; you can also edit it by hand. Check it with
         * `bin/sightpress site:registry:check`. See ADR-0002.
         */


        PHP;

    public function __construct(public string $path)
    {
    }

    /**
     * What the file returns; an empty registry when the file doesn't exist yet.
     */
    public function read(): mixed
    {
        if (!is_file($this->path)) {
            return ['version' => SiteRegistryFormat::VERSION, 'sites' => []];
        }

        $path = $this->path;

        return (static fn (): mixed => require $path)();
    }

    /**
     * Changes the file under an exclusive lock. `$change` gets what the file returns now and returns the new
     * array, or null to leave the file as it is.
     *
     * @param callable(mixed): (array<mixed>|null) $change
     */
    public function update(callable $change): void
    {
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new SiteRegistryException(sprintf(
                'The site registry %s can\'t be locked: check that its folder exists and is writable.',
                $this->path,
            ));
        }

        try {
            flock($lock, LOCK_EX);
            $registry = $change($this->read());
            if ($registry !== null) {
                $this->write($registry);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<mixed> $registry
     */
    private function write(array $registry): void
    {
        $temporary = sprintf('%s/.%s.%s.tmp', dirname($this->path), basename($this->path), bin2hex(random_bytes(6)));
        $exists = is_file($this->path);

        $written = (!$exists || @copy($this->path, $this->path . '.bak'))
            && @file_put_contents($temporary, self::HEADER . 'return ' . var_export($registry, true) . ";\n") !== false
            && (!$exists || @chmod($temporary, fileperms($this->path) & 0o777))
            && @rename($temporary, $this->path);

        if (!$written) {
            @unlink($temporary);

            throw new SiteRegistryException(sprintf(
                'The site registry %s could not be written: check that its folder is writable.',
                $this->path,
            ));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }
}
