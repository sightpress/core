<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use DateTimeImmutable;

/**
 * One file on its way to the media library, sent in parts: to the server's temporary folder, or straight to the
 * site's bucket (`direct`), where it is checked once it has arrived.
 */
final readonly class UploadSession
{
    public const string TEMP = 'temp';

    public const string DIRECT = 'direct';

    public const string RECEIVING = 'receiving';

    public const string VALIDATING = 'validating';

    public const string PROCESSING = 'processing';

    public const string REFUSED = 'refused';

    /**
     * @param array<mixed>|null $refusal why the file was refused, as {@see \SightPress\Core\Template\Message::export()}
     */
    public function __construct(
        public string $id,
        public int $userId,
        public string $fileName,
        public int $totalBytes,
        public int $receivedBytes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $completedAt = null,
        public ?int $mediaId = null,
        public string $mode = self::TEMP,
        public string $state = self::RECEIVING,
        public ?string $remoteId = null,
        public ?int $partBytes = null,
        public ?array $refusal = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }

    public function isDirect(): bool
    {
        return $this->mode === self::DIRECT;
    }

    /**
     * How many parts a direct upload is split into.
     */
    public function partCount(): int
    {
        return (int) ceil($this->totalBytes / max(1, (int) $this->partBytes));
    }

    /**
     * The size of one part of a direct upload: all the same but the last.
     */
    public function partSize(int $number): int
    {
        return $number < $this->partCount()
            ? (int) $this->partBytes
            : $this->totalBytes - ($this->partCount() - 1) * (int) $this->partBytes;
    }
}
