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

    public function testFindsTheVideosThatUseACaptionsFile(): void
    {
        $database = new CoreSiteDatabase();
        $repository = new MediaRepository($database->entityManager);
        $format = new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4');
        $now = new DateTimeImmutable('2026-11-23 10:00:00 UTC');
        $videos = [];
        foreach ([1, 2, 3] as $number) {
            $videos[$number] = new Media(str_repeat((string) $number, 32), str_repeat((string) $number, 64), $format, 'v.mp4', 'V.mp4', 1, null, null, [], null, $now);
        }
        $videos[1]->setCaptionFile('en-GB', 40, $now);
        $videos[2]->setCaptionFile('pt-PT', 41, $now);
        foreach ($videos as $video) {
            $repository->save($video);
        }
        $database->entityManager->clear();

        self::assertSame([(int) $videos[1]->id()], array_map(static fn (Media $video): int => (int) $video->id(), $repository->videosCaptionedBy(40)));
        self::assertSame([], $repository->videosCaptionedBy(99));
    }

    public function testListsTheNewestFilesAPageAtATime(): void
    {
        $database = new CoreSiteDatabase();
        $repository = new MediaRepository($database->entityManager);
        $format = new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF');
        foreach (['09:00', '11:00', '10:00', '11:00'] as $index => $time) {
            $repository->save(new Media(str_repeat(dechex($index + 1), 32), str_repeat(dechex($index + 1), 64), $format, 'f.pdf', "F{$index}.pdf", 1, null, null, [], null, new DateTimeImmutable("2026-11-16 {$time}:00 UTC")));
        }

        self::assertSame(4, $repository->count());
        self::assertSame(['F3.pdf', 'F1.pdf'], array_map(static fn (Media $media): string => $media->originalName(), $repository->newest(2)));
        self::assertSame(['F2.pdf', 'F0.pdf'], array_map(static fn (Media $media): string => $media->originalName(), $repository->newest(2, 2)));
    }

    public function testOffersFilesToChooseFromByKindAndType(): void
    {
        $database = new CoreSiteDatabase();
        $repository = new MediaRepository($database->entityManager);
        $formats = [
            'P.pdf' => new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'),
            'C.vtt' => new MediaFormat('text/vtt', MediaKind::Document, 'vtt', 'WebVTT captions'),
            'I.png' => new MediaFormat('image/png', MediaKind::Image, 'png', 'PNG image'),
        ];
        $index = 0;
        foreach ($formats as $name => $format) {
            ++$index;
            $repository->save(new Media(str_repeat(dechex($index), 32), str_repeat(dechex($index), 64), $format, 'f', $name, 1, null, null, [], null, new DateTimeImmutable("2026-11-16 1{$index}:00:00 UTC")));
        }
        $names = static fn (array $files): array => array_map(static fn (mixed $media): string => $media instanceof Media ? $media->originalName() : '', $files);

        self::assertSame(['I.png', 'C.vtt', 'P.pdf'], $names($repository->choices()));
        self::assertSame(['C.vtt', 'P.pdf'], $names($repository->choices(MediaKind::Document)));
        self::assertSame(['C.vtt'], $names($repository->choices(MediaKind::Document, 'text/vtt')));
        self::assertSame(['I.png'], $names($repository->choices(limit: 1)));
    }

    public function testLooksFilesUpByIdForOtherModules(): void
    {
        $database = new CoreSiteDatabase();
        $repository = new MediaRepository($database->entityManager);
        $now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
        $format = new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF');
        $one = new Media(str_repeat('a1', 16), str_repeat('1', 64), $format, 'a.pdf', 'A.pdf', 10, null, null, [], null, $now);
        $two = new Media(str_repeat('b2', 16), str_repeat('2', 64), $format, 'b.pdf', 'B.pdf', 10, null, null, [], null, $now);
        $repository->save($one);
        $repository->save($two);
        $ids = [(int) $one->id(), (int) $two->id()];

        self::assertSame($one, $repository->file($ids[0]));
        self::assertNull($repository->file(999));
        self::assertSame([], $repository->files([]));
        $files = $repository->files([$ids[1], $ids[0], $ids[1], 999]);
        ksort($files);
        self::assertSame([$ids[0] => $one, $ids[1] => $two], $files);
    }
}
