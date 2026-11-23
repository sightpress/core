<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use DateInterval;
use Psr\Clock\ClockInterface;
use SightPress\Core\Http\Upload\DirectUploadCheck;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Template\Message;

/**
 * Large files sent from the browser straight to the site's bucket (D39), so they never pass through PHP. The server
 * only signs part addresses, checks what arrived, and then treats the file like any other upload.
 */
final readonly class DirectUploads
{
    public const int MAX_PARTS = 9999;

    public const int URL_BATCH = 20;

    private const string STALE_CHECK = 'PT30M';

    public function __construct(
        private UploadSessions $sessions,
        private BucketUploads $bucket,
        private DirectUploadCheck $check,
        private ChunkedUploads $chunked,
        private MediaUploader $uploader,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Part size: from 8 MB, in whole megabytes, so that a file never needs more than {@see MAX_PARTS}.
     */
    public static function partBytes(int $size): int
    {
        $megabytes = (int) ceil($size / self::MAX_PARTS / MediaLimits::MEGABYTE);

        return max(ChunkedUploads::CHUNK_BYTES, $megabytes * MediaLimits::MEGABYTE);
    }

    public function status(string $origin): string
    {
        return $this->check->status($origin);
    }

    /**
     * Starts an upload, or returns null when this site must use the temporary folder instead.
     */
    public function start(int $userId, string $name, string $type, int $size, string $origin): ?UploadSession
    {
        if ($this->check->status($origin) !== DirectUploadCheck::READY) {
            return null;
        }
        $name = $this->chunked->admit($userId, $name, $type, $size);
        $id = bin2hex(random_bytes(16));
        try {
            $remote = $this->bucket->start($id);
        } catch (StorageException) {
            return null;
        }
        $now = $this->clock->now();
        $session = new UploadSession($id, $userId, $name, $size, 0, $now, $now, mode: UploadSession::DIRECT, remoteId: $remote, partBytes: self::partBytes($size));
        $this->sessions->add($session);

        return $session;
    }

    /**
     * The person's own direct upload, or null.
     */
    public function find(string $id, int $userId): ?UploadSession
    {
        $session = $this->sessions->find($id);

        return $session?->userId === $userId && $session->isDirect() ? $session : null;
    }

    /**
     * Signed addresses for a batch of parts.
     *
     * @param list<int> $numbers
     *
     * @return array<int, string> by part number
     */
    public function urls(UploadSession $session, array $numbers): array
    {
        if ($session->state !== UploadSession::RECEIVING || $session->isComplete()) {
            throw new UploadRefused(409, new Message('This upload has already ended.'));
        }
        if ($numbers === [] || count($numbers) > self::URL_BATCH) {
            throw new UploadRefused(400, new Message('This part doesn\'t follow the last one received.'));
        }
        $urls = [];
        foreach ($numbers as $number) {
            if ($number < 1 || $number > $session->partCount()) {
                throw new UploadRefused(400, new Message('This part doesn\'t follow the last one received.'));
            }
            $urls[$number] = $this->bucket->partUrl($session, $number);
        }
        $this->sessions->received($session->id, $session->receivedBytes, $this->clock->now());

        return $urls;
    }

    /**
     * The parts that arrived whole, for resuming.
     *
     * @return array<int, string> part number => ETag
     */
    public function received(UploadSession $session): array
    {
        if ($session->state !== UploadSession::RECEIVING || $session->isComplete()) {
            return [];
        }
        $good = [];
        foreach ($this->bucket->parts($session) as $number => $part) {
            if ($number <= $session->partCount() && $part['size'] === $session->partSize($number)) {
                $good[$number] = $part['etag'];
            }
        }

        return $good;
    }

    /**
     * Joins the parts once the bucket confirms each one, then leaves the file to be checked.
     *
     * @param array<int, string> $etags what the browser was told, by part number
     */
    public function complete(UploadSession $session, array $etags): void
    {
        if ($session->state === UploadSession::VALIDATING || $session->state === UploadSession::PROCESSING) {
            return;
        }
        if ($session->state !== UploadSession::RECEIVING || $session->isComplete()) {
            throw new UploadRefused(409, new Message('This upload has already ended.'));
        }
        $stored = $this->received($session);
        if (count($stored) !== $session->partCount()) {
            throw new UploadRefused(409, new Message('This part doesn\'t follow the last one received.'));
        }
        foreach ($stored as $number => $etag) {
            if (trim($etags[$number] ?? '', '"') !== $etag) {
                throw new UploadRefused(409, new Message('This part doesn\'t follow the last one received.'));
            }
        }
        try {
            $this->bucket->complete($session, $stored);
        } catch (StorageException) {
            throw new UploadRefused(409, new Message('Another part of this file is still being received.'));
        }
        $now = $this->clock->now();
        $this->sessions->received($session->id, $session->totalBytes, $now);
        $this->sessions->transition($session->id, UploadSession::RECEIVING, UploadSession::VALIDATING, $now);
    }

    /**
     * Checks one arrived file. A refused file is deleted from the bucket and the reason kept for the person. A
     * storage problem leaves it to be tried again by the next scheduler run.
     */
    public function validate(string $id): void
    {
        $session = $this->sessions->find($id);
        if ($session === null || !$this->claim($session)) {
            return;
        }
        $file = $this->bucket->file($session);
        try {
            if ($file->size() !== $session->totalBytes) {
                throw new MediaException('Only part of the file arrived. Try uploading it again.');
            }
            $result = $this->uploader->adopt($file, $session->fileName, $session->userId);
            $this->sessions->complete($session->id, (int) $result->media->id(), $this->clock->now());
        } catch (MediaException $exception) {
            try {
                $this->bucket->abort($session);
            } catch (StorageException) {
                // The session stays until it expires, and that removes the file.
            }
            $this->sessions->refuse($session->id, $exception->text()->export(), $this->clock->now());
        } catch (StorageException) {
            $this->sessions->transition($session->id, UploadSession::PROCESSING, UploadSession::VALIDATING, $this->clock->now());
        }
    }

    /**
     * Checks every file waiting, including one whose check stopped halfway.
     *
     * @return int how many were looked at
     */
    public function validateWaiting(): int
    {
        $waiting = $this->sessions->awaitingValidation($this->clock->now()->sub(new DateInterval(self::STALE_CHECK)));
        foreach ($waiting as $session) {
            $this->validate($session->id);
        }

        return count($waiting);
    }

    /**
     * Stops the upload in the bucket and forgets it.
     */
    public function cancel(UploadSession $session): void
    {
        if (!$session->isComplete()) {
            $this->bucket->abort($session);
        }
        $this->sessions->delete($session->id);
    }

    /**
     * Removes direct uploads idle for 24 hours and old records. One the bucket can't abort now is tried again later.
     *
     * @return int how many
     */
    public function purgeExpired(): int
    {
        $now = $this->clock->now();
        $purged = 0;
        foreach ($this->sessions->expired($now->sub(new DateInterval(ChunkedUploads::IDLE_LIMIT)), $now->sub(new DateInterval(ChunkedUploads::FINISHED_LIMIT)), UploadSession::DIRECT) as $session) {
            try {
                $this->cancel($session);
                ++$purged;
            } catch (StorageException) {
                continue;
            }
        }

        return $purged;
    }

    private function claim(UploadSession $session): bool
    {
        $now = $this->clock->now();
        if ($session->state === UploadSession::VALIDATING) {
            return $this->sessions->transition($session->id, UploadSession::VALIDATING, UploadSession::PROCESSING, $now);
        }

        return $session->state === UploadSession::PROCESSING
            && $session->updatedAt < $now->sub(new DateInterval(self::STALE_CHECK))
            && $this->sessions->transition($session->id, UploadSession::PROCESSING, UploadSession::PROCESSING, $now);
    }
}
