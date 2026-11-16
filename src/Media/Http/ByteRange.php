<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Http;

/**
 * One byte range of a file (RFC 9110 §14), from a `Range: bytes=…` header. Only single ranges are served; a list
 * of ranges is answered with the whole file, which the RFC allows.
 */
final readonly class ByteRange
{
    /**
     * @param int $start first byte, counted from 0
     * @param int $end   last byte, included
     */
    private function __construct(
        public int $start,
        public int $end,
    ) {
    }

    /**
     * The range a header asks for in a file of `$size` bytes; null when the header is not a single byte range
     * (then the whole file is sent).
     *
     * @throws UnsatisfiableRange when the range starts after the end of the file
     */
    public static function parse(string $header, int $size): ?self
    {
        if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $match) !== 1 || $match[1] . $match[2] === '') {
            return null;
        }

        if ($match[1] === '') {
            $suffix = (int) $match[2];
            if ($suffix === 0 || $size === 0) {
                throw new UnsatisfiableRange();
            }

            return new self(max(0, $size - $suffix), $size - 1);
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        if ($match[2] !== '' && (int) $match[2] < $start) {
            return null;
        }
        if ($start >= $size) {
            throw new UnsatisfiableRange();
        }

        return new self($start, $end);
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    /**
     * The `Content-Range` value for a file of `$size` bytes.
     */
    public function contentRange(int $size): string
    {
        return sprintf('bytes %d-%d/%d', $this->start, $this->end, $size);
    }
}
