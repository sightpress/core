<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Psr\Log\LoggerInterface;

/**
 * Writes the unavailable external modules to the log once, not on every request: the list is remembered in the
 * cache folder and logged again only when it changes (or after `cache:clear`). Without a cache folder (debug mode)
 * every boot logs it.
 */
final readonly class UnavailableModuleLog
{
    /**
     * @param string|null $stateFile where the last logged list is remembered
     */
    public function __construct(private LoggerInterface $logger, private ?string $stateFile = null)
    {
    }

    public static function inCacheFolder(LoggerInterface $logger, ?string $cacheDir): self
    {
        return new self($logger, $cacheDir === null ? null : rtrim($cacheDir, '/') . '/modules-unavailable.txt');
    }

    /**
     * @param list<UnavailableModule> $unavailable
     */
    public function report(array $unavailable): void
    {
        $lines = array_map(static fn (UnavailableModule $module): string => $module->identity() . ': ' . $module->reason, $unavailable);
        $state = implode("\n", $lines);
        if ($this->stateFile !== null && (is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : '') === $state) {
            return;
        }
        foreach ($unavailable as $module) {
            $this->logger->error('Module {module} is unavailable and was left out of every site: {reason}', [
                'module' => $module->identity(),
                'reason' => $module->reason,
            ]);
        }
        if ($this->stateFile !== null) {
            @mkdir(dirname($this->stateFile), 0o777, true);
            @file_put_contents($this->stateFile, $state);
        }
    }
}
