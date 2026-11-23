<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use InvalidArgumentException;
use SightPress\Core\Http\Upload\IniSize;

/**
 * App limits for uploads that replace the ones below them: a site's override in `config/sites.php`, or the tenant's
 * `media.limits` and `media.limits.max`. Null leaves a value to the layer below.
 *
 * Sizes are in bytes and can be written as `20M` or `1G`; `pixels` is width × height.
 */
final readonly class SiteMediaLimits
{
    public const array KEYS = ['image', 'video', 'audio', 'document', 'pixels'];

    /**
     * @param positive-int|null $image
     * @param positive-int|null $video
     * @param positive-int|null $audio
     * @param positive-int|null $document
     * @param positive-int|null $pixels
     */
    public function __construct(
        public ?int $image = null,
        public ?int $video = null,
        public ?int $audio = null,
        public ?int $document = null,
        public ?int $pixels = null,
    ) {
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidArgumentException naming the key, when a key is unknown or a value isn't a positive size
     */
    public static function fromArray(array $values): self
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException(sprintf('unknown key "%s"; use %s.', $key, implode(', ', self::KEYS)));
            }
        }

        return new self(
            self::size($values, 'image'),
            self::size($values, 'video'),
            self::size($values, 'audio'),
            self::size($values, 'document'),
            self::pixels($values),
        );
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * The values that are set, in bytes and pixels.
     *
     * @return array<'image'|'video'|'audio'|'document'|'pixels', positive-int>
     */
    public function toArray(): array
    {
        return array_filter([
            'image' => $this->image,
            'video' => $this->video,
            'audio' => $this->audio,
            'document' => $this->document,
            'pixels' => $this->pixels,
        ], is_int(...));
    }

    /**
     * These values, with the ones set in `$other` replacing them.
     */
    public function merge(self $other): self
    {
        return new self(
            $other->image ?? $this->image,
            $other->video ?? $this->video,
            $other->audio ?? $this->audio,
            $other->document ?? $this->document,
            $other->pixels ?? $this->pixels,
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return positive-int|null
     */
    private static function size(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $bytes = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\s*\d+\s*[kmg]?\s*$/i', $value) === 1 => IniSize::bytes($value),
            default => null,
        };
        if ($bytes === null || $bytes < 1) {
            throw new InvalidArgumentException(sprintf('"%s" must be a size above zero, such as 20M or 1G.', $key));
        }

        return $bytes;
    }

    /**
     * @param array<mixed> $values
     *
     * @return positive-int|null
     */
    private static function pixels(array $values): ?int
    {
        $value = $values['pixels'] ?? null;
        if ($value === null) {
            return null;
        }
        $pixels = is_string($value) && ctype_digit($value) ? (int) $value : $value;
        if (!is_int($pixels) || $pixels < 1) {
            throw new InvalidArgumentException('"pixels" must be a whole number above zero, such as 25000000.');
        }

        return $pixels;
    }
}
