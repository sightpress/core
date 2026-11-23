<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Media;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\UploadRefused;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * Files sent in parts through a real site container, on every database engine.
 */
#[CoversNothing]
final class ChunkedUploadTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('chunked');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testPartsBecomeOneFileWithTheRightChecksum(): void
    {
        $content = "name,points\n" . str_repeat("Ada,10\n", 2000);
        $uploads = Services::get($this->site->container(), ChunkedUploads::class);
        $session = $uploads->create(1, 'Points.csv', 'text/csv', strlen($content));

        $offset = 0;
        $result = null;
        foreach (str_split($content, 4096) as $part) {
            $current = $uploads->find($session->id, 1);
            self::assertNotNull($current);
            self::assertSame($offset, $current->receivedBytes);
            $result = $uploads->append($current, $offset, Stream::create($part), strlen($part), hash('sha256', $part, true))['result'];
            $offset += strlen($part);
        }

        self::assertNotNull($result);
        $stored = Services::get($this->site->container(), MediaRepository::class)->findByKey($result->media->key());
        self::assertSame(hash('sha256', $content), $stored?->checksum());
        self::assertSame((int) $result->media->id(), $uploads->find($session->id, 1)?->mediaId);
    }

    public function testSizesOverFourGigabytesAndExpiryWorkOnThisDatabase(): void
    {
        $container = $this->site->container();
        $connection = Services::get($container, Connection::class);
        $uploads = Services::get($container, ChunkedUploads::class);
        $session = $uploads->create(1, 'Talk.pdf', 'application/pdf', 1024);
        $large = 5 * 1024 ** 3;
        $connection->update(UploadSessions::TABLE, ['total_bytes' => $large, 'received_bytes' => $large - 1], ['id' => $session->id]);

        $found = $uploads->find($session->id, 1);
        self::assertSame([$large, $large - 1], [$found?->totalBytes, $found?->receivedBytes]);
        self::assertSame(1, Services::get($container, UploadSessions::class)->outstandingBytes());
        self::assertSame(['sessions' => 1, 'bytes' => $large], Services::get($container, UploadSessions::class)->activeFor(1));

        self::assertSame(0, $uploads->purgeExpired());
        $connection->update(UploadSessions::TABLE, ['updated_at' => new \DateTimeImmutable()->sub(new DateInterval('P2D'))], ['id' => $session->id], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        self::assertSame(1, $uploads->purgeExpired());
        self::assertNull($uploads->find($session->id, 1));
    }

    public function testAFileOverTwoGigabytesKeepsItsSize(): void
    {
        $container = $this->site->container();
        $large = 5 * 1024 ** 3;
        $media = new Media(str_repeat('c', 32), str_repeat('d', 64), new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4'), 'talk.mp4', 'Talk.mp4', $large, null, null, [], 1, new \DateTimeImmutable());
        Services::get($container, MediaRepository::class)->save($media);
        $entities = Services::get($container, EntityManagerInterface::class);
        $entities->clear();

        $found = Services::get($container, MediaRepository::class)->findByKey(str_repeat('c', 32));
        self::assertNotNull($found);
        self::assertSame($large, $found->size());
    }

    public function testDirectUploadStatesWorkOnThisDatabase(): void
    {
        $sessions = Services::get($this->site->container(), UploadSessions::class);
        $now = new \DateTimeImmutable('2026-11-23 12:00:00 UTC');
        $id = str_repeat('e', 32);
        $sessions->add(new UploadSession($id, 1, 'Talk.mp4', 5 * 1024 ** 3, 0, $now, $now, mode: UploadSession::DIRECT, remoteId: str_repeat('r', 900), partBytes: 8 * 1024 * 1024));
        self::assertSame(0, $sessions->outstandingBytes(), 'a direct upload takes no room on this server');

        self::assertTrue($sessions->transition($id, UploadSession::RECEIVING, UploadSession::VALIDATING, $now));
        self::assertFalse($sessions->transition($id, UploadSession::RECEIVING, UploadSession::VALIDATING, $now));
        self::assertSame([$id], array_map(static fn (UploadSession $s): string => $s->id, $sessions->awaitingValidation($now)));

        self::assertTrue($sessions->transition($id, UploadSession::VALIDATING, UploadSession::PROCESSING, $now));
        self::assertSame([], $sessions->awaitingValidation($now->modify('-1 hour')));
        self::assertCount(1, $sessions->awaitingValidation($now->modify('+1 hour')));

        $sessions->refuse($id, ['text' => 'Refused.', 'parameters' => []], $now);
        $found = $sessions->find($id);
        self::assertSame([UploadSession::REFUSED, str_repeat('r', 900), 8 * 1024 * 1024, 'Refused.'], [$found?->state, $found?->remoteId, $found?->partBytes, $found?->refusal['text'] ?? null]);
        self::assertCount(1, $sessions->expired($now->modify('+1 hour'), $now, UploadSession::DIRECT));
        self::assertSame([], $sessions->expired($now->modify('+1 hour'), $now));
    }

    public function testAFakeFileLeavesNothingBehind(): void
    {
        $html = '<!doctype html><html><script>alert(1)</script></html>';
        $uploads = Services::get($this->site->container(), ChunkedUploads::class);
        $session = $uploads->create(1, 'report.pdf', 'application/pdf', strlen($html));

        try {
            $uploads->append($session, 0, Stream::create($html), strlen($html), null);
            self::fail('The file was accepted.');
        } catch (UploadRefused $refused) {
            self::assertSame(422, $refused->status);
        }

        self::assertNull($uploads->find($session->id, 1));
        self::assertSame([], glob($this->site->project->path() . '/var/uploads/' . $this->site->site()->id . '/*.part'));
    }
}
