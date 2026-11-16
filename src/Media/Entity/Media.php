<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Tenant\LocaleCode;

/**
 * A file in a site's media library. The stored file never changes; descriptions and the published flag do.
 *
 * Alt text and captions are kept per locale. New files are not published: only people who can manage media see
 * them until someone with `media.publish` publishes them. All times are UTC.
 */
#[ORM\Entity]
#[ORM\Table(name: 'media')]
final class Media implements MediaFile
{
    public const string KEY_PATTERN = '/^[0-9a-f]{32}$/';
    public const int ALT_MAX_LENGTH = 1000;
    public const int CAPTION_MAX_LENGTH = 2000;
    public const int TRANSCRIPT_MAX_LENGTH = 200_000;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'storage_key', type: Types::STRING, length: 32, unique: true)]
    private string $key;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $checksum;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MediaKind::class)]
    private MediaKind $kind;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $extension;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $variants;

    /** @var array<string, string> locale => text */
    #[ORM\Column(type: Types::JSON)]
    private array $alt = [];

    /** @var array<string, string> locale => text */
    #[ORM\Column(type: Types::JSON)]
    private array $caption = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transcript = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $published = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * @param string    $key          random, 32 lower-case hex characters
     * @param string    $checksum     SHA-256 of the uploaded file, in hex; the same upload is stored once
     * @param string    $fileName     safe name for URLs ({@see \SightPress\Core\Media\FileName::safe()})
     * @param int       $size         bytes of the stored file
     * @param list<int> $variants     widths of the smaller copies of a picture
     * @param ?int      $uploadedBy   user id; users live in another module's table, so there is no foreign key
     */
    public function __construct(
        string $key,
        string $checksum,
        MediaFormat $format,
        #[ORM\Column(name: 'file_name', type: Types::STRING, length: 100)]
        private string $fileName,
        #[ORM\Column(name: 'original_name', type: Types::STRING, length: 255)]
        private string $originalName,
        #[ORM\Column(type: Types::INTEGER)]
        private int $size,
        ?int $width,
        ?int $height,
        array $variants,
        #[ORM\Column(name: 'uploaded_by', type: Types::INTEGER, nullable: true)]
        private ?int $uploadedBy,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new MediaException('A media key must be 32 lower-case hex characters.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) {
            throw new MediaException('A media checksum must be a SHA-256 hash in lower-case hex.');
        }
        if (($width === null) !== ($height === null) || ($width !== null && ($width < 1 || $height < 1))) {
            throw new MediaException('A picture needs both a width and a height of at least 1 pixel.');
        }
        sort($variants);
        $this->key = $key;
        $this->checksum = $checksum;
        $this->kind = $format->kind;
        $this->mimeType = $format->mimeType;
        $this->extension = $format->extension;
        $this->width = $width;
        $this->height = $height;
        $this->variants = array_values(array_unique($variants));
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The id, once the file has been saved.
     */
    public function id(): ?int
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function kind(): MediaKind
    {
        return $this->kind;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Extension of the stored file, without the dot.
     */
    public function extension(): string
    {
        return $this->extension;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function variantWidths(): array
    {
        return $this->variants;
    }

    public function hasVariant(int $width): bool
    {
        return in_array($width, $this->variants, true);
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function altText(string $locale): ?string
    {
        return $this->alt[$locale] ?? null;
    }

    public function caption(string $locale): ?string
    {
        return $this->caption[$locale] ?? null;
    }

    public function transcript(): ?string
    {
        return $this->transcript;
    }

    public function uploadedBy(): ?int
    {
        return $this->uploadedBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Sets the alt text and caption for one locale; empty text removes it. Alt text `""` can't be stored here:
     * whether a picture is decorative is decided where it is used.
     */
    public function describe(string $locale, ?string $alt, ?string $caption, DateTimeImmutable $now): void
    {
        if (!LocaleCode::isValid($locale)) {
            throw new MediaException(sprintf('"%s" is not a locale code such as "en-GB".', $locale));
        }
        $this->alt = self::withText($this->alt, $locale, $alt, self::ALT_MAX_LENGTH, 'The alt text');
        $this->caption = self::withText($this->caption, $locale, $caption, self::CAPTION_MAX_LENGTH, 'The caption');
        $this->updatedAt = $now;
    }

    /**
     * Sets the transcript of a video or audio file; empty text removes it.
     */
    public function setTranscript(?string $transcript, DateTimeImmutable $now): void
    {
        if (!$this->kind->hasSpeech()) {
            throw new MediaException('Only video and audio files have a transcript.');
        }
        $transcript = self::text($transcript, self::TRANSCRIPT_MAX_LENGTH, 'The transcript');
        $this->transcript = $transcript;
        $this->updatedAt = $now;
    }

    public function publish(DateTimeImmutable $now): void
    {
        $this->published = true;
        $this->updatedAt = $now;
    }

    public function unpublish(DateTimeImmutable $now): void
    {
        $this->published = false;
        $this->updatedAt = $now;
    }

    /**
     * @param array<string, string> $texts
     *
     * @return array<string, string>
     */
    private static function withText(array $texts, string $locale, ?string $text, int $max, string $what): array
    {
        $text = self::text($text, $max, $what);
        if ($text === null) {
            unset($texts[$locale]);
        } else {
            $texts[$locale] = $text;
        }
        ksort($texts);

        return $texts;
    }

    private static function text(?string $text, int $max, string $what): ?string
    {
        $text = trim($text ?? '');
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new MediaException(sprintf('%s must be %d characters or fewer.', $what, $max));
        }

        return $text;
    }
}
