<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Template\Message;
use finfo;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UploadedFileInterface;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUploaded;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\Upload\IncomingFile;
use Throwable;

/**
 * Adds files to the site's media library.
 *
 * Every upload is checked on its content: the real type (finfo) must be on the {@see MediaTypes} allow-list and the
 * size within the {@see MediaLimits}. The same file uploaded twice (same SHA-256) is stored once: the second upload
 * returns the first entry. Pictures are re-encoded without metadata and get smaller copies ({@see ImageProcessor});
 * other files are stored as they are. Stored files get random names ({@see MediaFiles}). New files are not
 * published.
 */
final readonly class MediaUploader
{
    /** How much of a file in the bucket is read to tell its real type. */
    public const int SNIFF_BYTES = 1024 * 1024;

    private const string STORE_FAILED = 'The server couldn\'t store the upload. Try again, and ask your site administrator if it keeps happening.';

    public function __construct(
        private MediaTypes $types,
        private MediaLimits $limits,
        private ImageProcessor $images,
        private MediaFiles $files,
        private MediaRepository $media,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * Adds a file sent with a form.
     *
     * @param ?int $uploadedBy id of the signed-in user
     */
    public function uploadFile(UploadedFileInterface $upload, ?int $uploadedBy): UploadResult
    {
        $error = $upload->getError();
        if ($error !== UPLOAD_ERR_OK) {
            throw new MediaException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts. Make it smaller or ask your site administrator to raise the limit.',
                UPLOAD_ERR_PARTIAL => 'Only part of the file arrived. Try uploading it again.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                default => self::STORE_FAILED,
            });
        }

        $local = tempnam(sys_get_temp_dir(), 'sp-upload-');
        $local = is_string($local) ? $local : throw new MediaException(self::STORE_FAILED);
        try {
            $upload->moveTo($local);

            return $this->upload($local, $upload->getClientFilename() ?? '', $uploadedBy);
        } finally {
            if (is_file($local)) {
                unlink($local);
            }
        }
    }

    /**
     * Adds a local file. The file itself is left where it is.
     *
     * @param string $clientName the file's name on the uploader's computer
     * @param ?int   $uploadedBy id of the signed-in user, null for the console
     */
    public function upload(string $localFile, string $clientName, ?int $uploadedBy): UploadResult
    {
        return $this->add($localFile, $clientName, $uploadedBy, null);
    }

    /**
     * Adds a file from the command line: no limit on its size (D40), with every other check. The file is hashed and
     * stored as a stream, so its size doesn't matter; only pictures are decoded in memory, up to `$maxPixels`.
     */
    public function import(string $localFile, string $clientName, int $maxPixels): UploadResult
    {
        return $this->add($localFile, $clientName, null, $maxPixels);
    }

    /**
     * Adds a file that was sent straight to the bucket, with the checks of a normal upload: its real type from its
     * first bytes, its size, and its checksum from a stream. A picture is fetched and re-encoded; any other file is
     * copied to its final place inside the bucket. The incoming file is deleted once it is no longer needed.
     *
     * @throws MediaException when the file is refused; the incoming file is left for the caller to delete
     */
    public function adopt(IncomingFile $file, string $clientName, ?int $uploadedBy): UploadResult
    {
        $size = $file->size();
        if ($size === 0) {
            throw new MediaException('The file is empty. Choose another file.');
        }
        $start = $file->start(self::SNIFF_BYTES);
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($start);
        $format = $this->allowed(is_string($detected) ? $detected : '', $clientName, $size, $start, true);

        if ($format->kind === MediaKind::Image) {
            $local = tempnam(sys_get_temp_dir(), 'sp-upload-');
            $local = is_string($local) ? $local : throw new MediaException(self::STORE_FAILED);
            try {
                $file->saveTo($local);
                $result = $this->upload($local, $clientName, $uploadedBy);
            } finally {
                unlink($local);
            }
            $file->delete();

            return $result;
        }

        $checksum = $file->sha256();
        $existing = $this->media->findByChecksum($checksum);
        if ($existing !== null) {
            $file->delete();

            return new UploadResult($existing, true);
        }

        $key = bin2hex(random_bytes(16));
        $fileName = FileName::safe($clientName, $format->extension);
        $path = MediaFiles::path($key, $format->extension);
        $file->copyTo($path);
        try {
            $media = new Media($key, $checksum, $format, $fileName, FileName::original($clientName, $fileName), $size, null, null, [], $uploadedBy, $this->clock->now());
            $this->media->save($media);
        } catch (Throwable $exception) {
            $this->files->deletePaths([$path]);

            throw $exception;
        }
        $file->delete();
        $this->events->dispatch(new MediaUploaded((int) $media->id(), $key, $format->kind, $uploadedBy));

        return new UploadResult($media, false);
    }

    private function add(string $localFile, string $clientName, ?int $uploadedBy, ?int $importPixels): UploadResult
    {
        $size = is_file($localFile) && is_readable($localFile) ? filesize($localFile) : false;
        if ($size === false) {
            throw new MediaException('The uploaded file can\'t be read. Try uploading it again.');
        }
        if ($size === 0) {
            throw new MediaException('The file is empty. Choose another file.');
        }

        $detected = new finfo(FILEINFO_MIME_TYPE)->file($localFile);
        $format = $this->allowed(is_string($detected) ? $detected : '', $clientName, $size, (string) file_get_contents($localFile, length: 16), $importPixels === null);

        $checksum = (string) hash_file('sha256', $localFile);
        $existing = $this->media->findByChecksum($checksum);
        if ($existing !== null) {
            return new UploadResult($existing, true);
        }

        $key = bin2hex(random_bytes(16));
        $fileName = FileName::safe($clientName, $format->extension);
        $written = [];
        try {
            if ($format->kind === MediaKind::Image) {
                $image = $this->images->process($localFile, $format, $importPixels);
                $this->files->write($written[] = MediaFiles::path($key, $format->extension), $image->contents);
                foreach ($image->variants as $width => $contents) {
                    $this->files->write($written[] = MediaFiles::path($key, $format->extension, $width), $contents);
                }
                $media = new Media(
                    $key,
                    $checksum,
                    $format,
                    $fileName,
                    FileName::original($clientName, $fileName),
                    strlen($image->contents),
                    $image->width,
                    $image->height,
                    array_keys($image->variants),
                    $uploadedBy,
                    $this->clock->now(),
                );
            } else {
                $this->files->writeFile($written[] = MediaFiles::path($key, $format->extension), $localFile);
                $media = new Media(
                    $key,
                    $checksum,
                    $format,
                    $fileName,
                    FileName::original($clientName, $fileName),
                    $size,
                    null,
                    null,
                    [],
                    $uploadedBy,
                    $this->clock->now(),
                );
            }
            $this->media->save($media);
        } catch (Throwable $exception) {
            $this->files->deletePaths($written);

            throw $exception;
        }

        $this->events->dispatch(new MediaUploaded((int) $media->id(), $key, $format->kind, $uploadedBy));

        return new UploadResult($media, false);
    }

    /**
     * The file's format, after the checks on its real type and size.
     *
     * @param string $start the first bytes of the file
     */
    private function allowed(string $detected, string $clientName, int $size, string $start, bool $limited): MediaFormat
    {
        $format = $this->types->match($detected, $clientName);
        if ($format === null) {
            throw new MediaException(new Message('This type of file can\'t be uploaded. {types}', ['types' => $this->types->summary()]));
        }
        if ($format->mimeType === 'text/vtt' && !self::isWebVtt($start)) {
            throw new MediaException('This captions file isn\'t in WebVTT format: its first line must be "WEBVTT".');
        }
        $max = $this->limits->maxBytes($format->kind);
        if ($limited && $size > $max) {
            throw new MediaException(new Message('The file is {size}; {kind} can be at most {max}.', ['size' => MediaLimits::describe($size), 'kind' => self::plural($format->kind), 'max' => MediaLimits::describe($max)]));
        }

        return $format;
    }

    private static function isWebVtt(string $start): bool
    {
        return preg_match('/^(?:\xEF\xBB\xBF)?WEBVTT(?:[ \t\r\n]|$)/', $start) === 1;
    }

    private static function plural(MediaKind $kind): Message
    {
        return match ($kind) {
            MediaKind::Image => new Message('pictures'),
            MediaKind::Video => new Message('videos'),
            MediaKind::Audio => new Message('audio files'),
            MediaKind::Document => new Message('documents'),
        };
    }
}
