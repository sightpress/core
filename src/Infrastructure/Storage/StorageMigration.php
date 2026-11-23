<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage;

/**
 * The result of copying a site's files: what was copied, what was already there with the same checksum, and the paths
 * that could not be copied and checked.
 */
final readonly class StorageMigration
{
    /**
     * @param list<string> $verified every path now in the target with a matching checksum
     * @param list<string> $failed
     */
    public function __construct(
        public int $copied,
        public int $alreadyThere,
        public array $verified,
        public array $failed,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->failed === [];
    }
}
