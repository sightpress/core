<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use Closure;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;

/**
 * Where a site's files wait while their parts arrive: `<uploads.temp_path>/<site id>/<session id>.part`. Session ids
 * are random hex, so a path can't be steered by a request.
 */
final readonly class UploadFolder
{
    private string $directory;

    /**
     * @param (Closure(string): (float|false))|null $freeSpace measures free space (tests pass their own)
     */
    public function __construct(TenantConfig $config, Site $site, private ?Closure $freeSpace = null)
    {
        $this->directory = $config->uploadTempPath . '/' . $site->id;
    }

    public function path(string $id): string
    {
        return $this->directory . '/' . $id . '.part';
    }

    /**
     * Creates the folder if needed; false when it can't be written to.
     */
    public function prepare(): bool
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0o770, true);
        }

        return is_dir($this->directory) && is_writable($this->directory);
    }

    /**
     * Free bytes in the folder, or null when that can't be told.
     */
    public function free(): ?int
    {
        $free = $this->freeSpace === null ? @disk_free_space($this->directory) : ($this->freeSpace)($this->directory);

        return $free === false ? null : (int) $free;
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
