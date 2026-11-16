<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Looks files up in the site's media library, for modules that refer to them by id (blocks, branding).
 */
interface MediaLibrary
{
    public function file(int $id): ?MediaFile;

    /**
     * Several files in one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, MediaFile> keyed by id; ids that aren't in the library are left out
     */
    public function files(array $ids): array;
}
