<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `cache:clear`: empties the cache folder (compiled templates, database metadata, the list of installed themes), for
 * example after installing or removing a theme or a module with Composer. Everything is built again when needed.
 */
final readonly class ClearCacheCommand implements Command
{
    public function __construct(private TenantConfig $config)
    {
    }

    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Empties the cache folder, for example after installing or removing a theme or a module.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $folder = $this->config->cacheDir;
        $removed = 0;
        if (is_dir($folder)) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isDir() && !$entry->isLink()) {
                    @rmdir($entry->getPathname());
                } elseif (@unlink($entry->getPathname())) {
                    ++$removed;
                }
            }
        }
        $output->line(sprintf('Emptied the cache folder %s (%d %s).', $folder, $removed, $removed === 1 ? 'file' : 'files'));

        return self::SUCCESS;
    }
}
