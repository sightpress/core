<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Template\Message;

/**
 * The largest file of each kind someone can upload from the browser: the site's {@see MediaLimits}, lowered to what
 * one request can carry ({@see ServerLimits::requestLimit()}). Shown next to the upload field, and checked in the
 * browser before a file is sent.
 */
final readonly class UploadAllowance
{
    public function __construct(
        private MediaLimits $limits,
        private ServerLimits $server,
    ) {
    }

    /**
     * @return positive-int
     */
    public function maxBytes(MediaKind $kind): int
    {
        return $this->server->cap($this->limits->maxBytes($kind));
    }

    /**
     * Whether the server, not the site's own limit, decides how large a file of this kind can be.
     */
    public function limitedByServer(MediaKind $kind): bool
    {
        return $this->maxBytes($kind) < $this->limits->maxBytes($kind);
    }

    /**
     * "Largest file you can upload: pictures 20 MB, documents 50 MB, audio files 64 MB and videos 64 MB."
     */
    public function summary(): Message
    {
        return new Message('Largest file you can upload: {sizes}.', ['sizes' => Message::list(array_map(
            fn (MediaKind $kind): Message => new Message('{kind} {size}', ['kind' => self::plural($kind), 'size' => MediaLimits::describe($this->maxBytes($kind))]),
            [MediaKind::Image, MediaKind::Document, MediaKind::Audio, MediaKind::Video],
        ))]);
    }

    /**
     * The limits for the browser-side check, by the start of the file's type: `image`, `video`, `audio`, and `*` for
     * everything else (documents).
     *
     * @return array{image: int, video: int, audio: int, '*': int}
     */
    public function forBrowser(): array
    {
        return [
            'image' => $this->maxBytes(MediaKind::Image),
            'video' => $this->maxBytes(MediaKind::Video),
            'audio' => $this->maxBytes(MediaKind::Audio),
            '*' => $this->maxBytes(MediaKind::Document),
        ];
    }

    public static function plural(MediaKind $kind): Message
    {
        return match ($kind) {
            MediaKind::Image => new Message('pictures'),
            MediaKind::Video => new Message('videos'),
            MediaKind::Audio => new Message('audio files'),
            MediaKind::Document => new Message('documents'),
        };
    }
}
