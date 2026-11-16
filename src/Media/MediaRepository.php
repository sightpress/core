<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Entity\Media;

/**
 * The site's media library, in the site's own database.
 */
final readonly class MediaRepository implements MediaLibrary
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(int $id): ?Media
    {
        return $this->entityManager->find(Media::class, $id);
    }

    public function file(int $id): ?Media
    {
        return $this->find($id);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Media>
     */
    public function files(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<Media> $found */
        $found = $this->entityManager->getRepository(Media::class)->findBy(['id' => array_values(array_unique($ids))]);
        $files = [];
        foreach ($found as $media) {
            $files[(int) $media->id()] = $media;
        }

        return $files;
    }

    public function findByKey(string $key): ?Media
    {
        if (preg_match(Media::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        return $this->entityManager->getRepository(Media::class)->findOneBy(['key' => $key]);
    }

    public function findByChecksum(string $checksum): ?Media
    {
        return $this->entityManager->getRepository(Media::class)->findOneBy(['checksum' => $checksum]);
    }

    /**
     * A page of the library, newest first.
     *
     * @return list<Media>
     */
    public function newest(int $limit, int $offset = 0): array
    {
        /** @var list<Media> */
        return $this->entityManager->getRepository(Media::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(Media::class)->count();
    }

    /**
     * Saves a new file (which gets its id) or the changes to an existing one.
     */
    public function save(Media $media): void
    {
        $this->entityManager->persist($media);
        $this->entityManager->flush();
    }

    /**
     * Removes the row; the stored files are removed with {@see MediaFiles::delete()}.
     */
    public function remove(Media $media): void
    {
        $this->entityManager->remove($media);
        $this->entityManager->flush();
    }
}
