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

    /**
     * Files to choose from, for example in the page editor: newest first, of one kind (and one type, such as
     * `text/vtt` for captions) or of any kind.
     *
     * @param positive-int $limit
     *
     * @return list<MediaFile>
     */
    public function choices(?MediaKind $kind = null, ?string $mimeType = null, int $limit = 200): array;
}
