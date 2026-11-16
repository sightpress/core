<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(MediaRepository::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaRepositoryTest extends TestCase
{
    public function testSavesFindsAndRemovesFiles(): void
    {
        $database = new CoreSiteDatabase();
        $repository = new MediaRepository($database->entityManager);
        $key = str_repeat('ab', 16);
        $media = new Media($key, str_repeat('d', 64), new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'), 'a.pdf', 'A.pdf', 10, null, null, [], null, new DateTimeImmutable('2026-11-16 10:00:00 UTC'));
        $media->describe('en-GB', 'Alt', 'Caption', new DateTimeImmutable('2026-11-16 10:00:00 UTC'));

        $repository->save($media);
        $id = (int) $media->id();
        $database->entityManager->clear();

        $found = $repository->find($id);
        self::assertNotNull($found);
        self::assertSame(['Alt', 'Caption', MediaKind::Document], [$found->altText('en-GB'), $found->caption('en-GB'), $found->kind()]);
        self::assertSame($found, $repository->findByKey($key));
        self::assertSame($found, $repository->findByChecksum(str_repeat('d', 64)));
        self::assertNull($repository->findByKey(str_repeat('cd', 16)));
        self::assertNull($repository->findByKey('../../etc/passwd'));

        $repository->remove($found);

        self::assertNull($repository->find($id));
    }
}
