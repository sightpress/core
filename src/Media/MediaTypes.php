<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaKind;

/**
 * The allow-list of file types the media library accepts, matched on the type finfo detects from the content,
 * never on the name or the type the browser sent. SVG is not accepted: it can carry scripts.
 */
final readonly class MediaTypes
{
    /**
     * Detected types that stand for more than their own format, or that finfo spells differently: CSV files are
     * usually detected as plain text.
     */
    private const array DETECTED = [
        'text/plain' => ['text/plain', 'text/csv'],
        'audio/x-m4a' => ['audio/mp4'],
        'audio/x-wav' => ['audio/wav'],
        'audio/wave' => ['audio/wav'],
        'audio/vnd.wave' => ['audio/wav'],
        'application/x-zip-compressed' => ['application/zip'],
    ];

    /** @var array<string, list<MediaFormat>> detected MIME type => formats; the first is the default */
    private array $formats;

    public function __construct()
    {
        $byType = [];
        foreach (self::all() as $format) {
            $byType[$format->mimeType] = $format;
        }
        $formats = array_map(static fn (MediaFormat $format): array => [$format], $byType);
        foreach (self::DETECTED as $detected => $mimeTypes) {
            $formats[$detected] = array_map(static fn (string $type): MediaFormat => $byType[$type], $mimeTypes);
        }
        $this->formats = $formats;
    }

    /**
     * The format of a file, or null when its type is not accepted.
     *
     * @param string $detectedType   what finfo says the content is
     * @param string $clientFileName the name on the uploader's computer; only picks between formats with the
     *                               same detected type (a `.csv` file detected as plain text is CSV)
     */
    public function match(string $detectedType, string $clientFileName): ?MediaFormat
    {
        $candidates = $this->formats[strtolower($detectedType)] ?? [];
        $extension = strtolower(pathinfo($clientFileName, PATHINFO_EXTENSION));
        foreach ($candidates as $format) {
            if ($format->extension === $extension) {
                return $format;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * A sentence listing what can be uploaded, for error messages.
     */
    public function summary(): string
    {
        $labels = array_fill_keys(array_map(static fn (MediaKind $kind): string => $kind->value, MediaKind::cases()), []);
        foreach (self::all() as $format) {
            $labels[$format->kind->value][$format->label] = $format->label;
        }

        return sprintf(
            'You can upload pictures (%s), video (%s), audio (%s) and documents (%s).',
            ...array_map(static fn (array $names): string => implode(', ', $names), array_values($labels)),
        );
    }

    /**
     * @return list<MediaFormat>
     */
    public static function all(): array
    {
        $image = MediaKind::Image;
        $video = MediaKind::Video;
        $audio = MediaKind::Audio;
        $document = MediaKind::Document;

        return [
            new MediaFormat('image/jpeg', $image, 'jpg', 'JPEG'),
            new MediaFormat('image/png', $image, 'png', 'PNG'),
            new MediaFormat('image/webp', $image, 'webp', 'WebP'),
            new MediaFormat('image/gif', $image, 'gif', 'GIF'),
            new MediaFormat('video/mp4', $video, 'mp4', 'MP4'),
            new MediaFormat('video/webm', $video, 'webm', 'WebM'),
            new MediaFormat('audio/mpeg', $audio, 'mp3', 'MP3'),
            new MediaFormat('audio/mp4', $audio, 'm4a', 'M4A'),
            new MediaFormat('audio/ogg', $audio, 'ogg', 'Ogg'),
            new MediaFormat('audio/wav', $audio, 'wav', 'WAV'),
            new MediaFormat('application/pdf', $document, 'pdf', 'PDF'),
            new MediaFormat('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $document, 'docx', 'Word'),
            new MediaFormat('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $document, 'xlsx', 'Excel'),
            new MediaFormat('application/vnd.openxmlformats-officedocument.presentationml.presentation', $document, 'pptx', 'PowerPoint'),
            new MediaFormat('application/vnd.oasis.opendocument.text', $document, 'odt', 'OpenDocument text'),
            new MediaFormat('application/vnd.oasis.opendocument.spreadsheet', $document, 'ods', 'OpenDocument spreadsheet'),
            new MediaFormat('application/vnd.oasis.opendocument.presentation', $document, 'odp', 'OpenDocument presentation'),
            new MediaFormat('text/plain', $document, 'txt', 'plain text'),
            new MediaFormat('text/csv', $document, 'csv', 'CSV'),
            new MediaFormat('application/zip', $document, 'zip', 'ZIP'),
        ];
    }
}
