<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Media\UploadResult;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantConfig;

/**
 * Large files sent in parts to a temporary folder (D39). Each part is all or nothing: a part cut off or damaged on the
 * way is removed again, so the stored offset is always the end of the last good part. After the last part the file
 * goes through {@see MediaUploader}, with every check a normal upload gets; a refused file is deleted.
 */
final readonly class ChunkedUploads
{
    public const int CHUNK_BYTES = 8 * MediaLimits::MEGABYTE;

    public const int MAX_ACTIVE_PER_USER = 5;

    public const string IDLE_LIMIT = 'PT24H';

    public const string FINISHED_LIMIT = 'P7D';

    private const int READ_BYTES = 65536;

    public function __construct(
        private UploadSessions $sessions,
        private UploadFolder $folder,
        private MediaTypes $types,
        private MediaLimits $limits,
        private MediaUploader $uploader,
        private ServerLimits $server,
        private TenantConfig $config,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The size of each part: 8 MB, or less when the server takes less in one request.
     *
     * @return positive-int
     */
    public function chunkBytes(): int
    {
        return $this->server->cap(self::CHUNK_BYTES);
    }

    public function maxBytes(): int
    {
        return $this->config->maxChunkedBytes;
    }

    /**
     * Starts an upload. Everything that can be known from the announced name, type and size is refused here, before
     * the first part is sent. The real type is only checked once the whole file has arrived.
     */
    public function create(int $userId, string $name, string $type, int $size): UploadSession
    {
        $name = $this->admit($userId, $name, $type, $size);
        if (!$this->folder->prepare()) {
            throw new UploadRefused(507, new Message('The server can\'t store uploads right now. Ask the person who runs it to check the upload folder.'));
        }
        $free = $this->folder->free();
        if ($free !== null && $free < $size + $this->sessions->outstandingBytes()) {
            throw new UploadRefused(507, new Message('The server doesn\'t have enough free space for this file. Ask the person who runs it to free some space.'));
        }

        $now = $this->clock->now();
        $session = new UploadSession(bin2hex(random_bytes(16)), $userId, $name, $size, 0, $now, $now);
        $this->sessions->add($session);

        return $session;
    }

    /**
     * The checks every large upload gets before its first part, wherever it is going.
     *
     * @return string the file name, cleaned
     */
    public function admit(int $userId, string $name, string $type, int $size): string
    {
        $name = mb_substr(trim(str_replace(["\0", '/', '\\'], '', $name)), 0, 255);
        if ($name === '') {
            throw new UploadRefused(400, new Message('Choose a file to upload.'));
        }
        if ($size < 1) {
            throw new UploadRefused(400, new Message('The file is empty. Choose another file.'));
        }
        if ($size > $this->config->maxChunkedBytes) {
            throw new UploadRefused(413, new Message('The file is {size}; the largest file this server accepts is {max}.', ['size' => MediaLimits::describe($size), 'max' => MediaLimits::describe($this->config->maxChunkedBytes)]));
        }
        $format = $this->announcedFormat($type, $name);
        if ($format === null) {
            throw new UploadRefused(415, new Message('This type of file can\'t be uploaded. {types}', ['types' => $this->types->summary()]));
        }
        $max = $this->limits->maxBytes($format->kind);
        if ($size > $max) {
            throw new UploadRefused(413, new Message('The file is {size}; files of this type can be at most {max}.', ['size' => MediaLimits::describe($size), 'max' => MediaLimits::describe($max)]));
        }
        $active = $this->sessions->activeFor($userId);
        if ($active['sessions'] >= self::MAX_ACTIVE_PER_USER) {
            throw new UploadRefused(429, new Message('You already have {count} uploads in progress. Finish or cancel one first.', ['count' => $active['sessions']]));
        }
        if ($active['bytes'] + $size > 2 * $this->config->maxChunkedBytes) {
            throw new UploadRefused(429, new Message('Your uploads in progress are too large together. Finish or cancel one first.'));
        }

        return $name;
    }

    /**
     * The person's own upload, or null: someone else's looks the same as one that doesn't exist.
     */
    public function find(string $id, int $userId): ?UploadSession
    {
        $session = $this->sessions->find($id);

        return $session?->userId === $userId && !$session->isDirect() ? $session : null;
    }

    /**
     * Stores one part at `$offset`. After the last part, adds the file to the library and returns the result. With an
     * empty part at the end of the file, tries that step again (for when the answer to the last part was lost).
     *
     * @param ?string $sha256 the part's expected SHA-256, raw
     *
     * @return array{session: UploadSession, result: ?UploadResult}
     */
    public function append(UploadSession $session, int $offset, StreamInterface $body, ?int $length, ?string $sha256): array
    {
        if ($session->isComplete()) {
            throw new UploadRefused(409, new Message('This upload has already ended.'));
        }
        $path = $this->folder->path($session->id);
        if ($session->receivedBytes > 0 && !is_file($path)) {
            throw self::missing();
        }
        $file = @fopen($path, 'c+b');
        if ($file === false) {
            throw self::missing();
        }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) {
                throw new UploadRefused(409, new Message('Another part of this file is still being received.'));
            }
            // Read again now that nobody else is writing.
            $current = $this->sessions->find($session->id);
            if ($current === null || $current->isComplete()) {
                $this->folder->delete($session->id); // opening it above may have created it again
                throw new UploadRefused(409, new Message('This upload has already ended.'));
            }
            if ($offset !== $current->receivedBytes) {
                throw new UploadRefused(409, new Message('This part doesn\'t follow the last one received.'));
            }
            $stat = fstat($file);
            if ($stat === false || $stat['size'] < $current->receivedBytes) {
                throw self::missing();
            }
            $received = $this->write($file, $current, $body, $length, $sha256);
            if ($received !== $current->receivedBytes) {
                $this->sessions->received($current->id, $received, $this->clock->now());
            }
            $updated = new UploadSession($current->id, $current->userId, $current->fileName, $current->totalBytes, $received, $current->createdAt, $this->clock->now());
            if ($received < $current->totalBytes) {
                return ['session' => $updated, 'result' => null];
            }

            return ['session' => $updated, 'result' => $this->finish($updated, $path)];
        } finally {
            fclose($file);
        }
    }

    public function cancel(UploadSession $session): void
    {
        $this->folder->delete($session->id);
        $this->sessions->delete($session->id);
    }

    /**
     * Deletes uploads idle for 24 hours and the records of those finished 7 days ago.
     *
     * @return int how many
     */
    public function purgeExpired(): int
    {
        $now = $this->clock->now();
        $expired = $this->sessions->expired($now->sub(new DateInterval(self::IDLE_LIMIT)), $now->sub(new DateInterval(self::FINISHED_LIMIT)));
        foreach ($expired as $session) {
            $this->cancel($session);
        }

        return count($expired);
    }

    /**
     * @param resource $file
     */
    private function write($file, UploadSession $session, StreamInterface $body, ?int $length, ?string $sha256): int
    {
        $start = max(0, $session->receivedBytes);
        ftruncate($file, $start); // what a request cut off earlier left behind
        fseek($file, $start);
        $room = min($session->totalBytes - $start, $this->chunkBytes());
        $hash = hash_init('sha256');
        $written = 0;
        while (!$body->eof()) {
            $data = $body->read(self::READ_BYTES);
            if ($data === '') {
                break;
            }
            $written += strlen($data);
            if ($written > $room) {
                ftruncate($file, $start);
                throw new UploadRefused(413, new Message('This part is larger than the server expected.'));
            }
            hash_update($hash, $data);
            if (@fwrite($file, $data) !== strlen($data)) {
                ftruncate($file, $start);
                throw new UploadRefused(507, new Message('The server doesn\'t have enough free space for this file. Ask the person who runs it to free some space.'));
            }
        }
        if ($length !== null && $written !== $length) {
            ftruncate($file, $start);
            throw new UploadRefused(400, new Message('Only part of the file arrived. Try uploading it again.'));
        }
        if ($sha256 !== null && !hash_equals($sha256, hash_final($hash, true))) {
            ftruncate($file, $start);
            throw new UploadRefused(460, new Message('A part of the file was damaged on the way. It will be sent again.'));
        }
        fflush($file);

        return $start + $written;
    }

    private function finish(UploadSession $session, string $path): UploadResult
    {
        try {
            $result = $this->uploader->upload($path, $session->fileName, $session->userId);
        } catch (MediaException $exception) {
            $this->cancel($session);

            throw new UploadRefused(422, $exception->text());
        }
        $this->sessions->complete($session->id, (int) $result->media->id(), $this->clock->now());
        $this->folder->delete($session->id);

        return $result;
    }

    /**
     * The format the browser announces, by type and then by the file's extension.
     */
    private function announcedFormat(string $type, string $name): ?MediaFormat
    {
        $format = $this->types->match($type, $name);
        if ($format !== null) {
            return $format;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        foreach (MediaTypes::all() as $candidate) {
            if ($candidate->extension === $extension) {
                return $candidate;
            }
        }

        return null;
    }

    private static function missing(): UploadRefused
    {
        return new UploadRefused(410, new Message('This server doesn\'t have the file\'s earlier parts. If the site runs on several servers, their upload folder isn\'t shared. Start the upload again, and tell the person who runs the server if it keeps happening.'));
    }
}
