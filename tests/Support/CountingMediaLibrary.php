<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaLibrary;

/**
 * A media library that remembers which ids each lookup asked for.
 */
final class CountingMediaLibrary implements MediaLibrary
{
    /** @var list<list<int>> */
    public array $calls = [];

    public function __construct(private readonly MediaLibrary $library)
    {
    }

    public function file(int $id): ?MediaFile
    {
        $this->calls[] = [$id];

        return $this->library->file($id);
    }

    public function files(array $ids): array
    {
        $this->calls[] = $ids;

        return $this->library->files($ids);
    }
}
