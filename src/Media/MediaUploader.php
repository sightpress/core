<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use finfo;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UploadedFileInterface;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUploaded;
use SightPress\Core\Media\Entity\Media;
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
        $size = is_file($localFile) && is_readable($localFile) ? filesize($localFile) : false;
        if ($size === false) {
            throw new MediaException('The uploaded file can\'t be read. Try uploading it again.');
        }
        if ($size === 0) {
            throw new MediaException('The file is empty. Choose another file.');
        }

        $detected = new finfo(FILEINFO_MIME_TYPE)->file($localFile);
        $format = $this->types->match(is_string($detected) ? $detected : '', $clientName);
        if ($format === null) {
            throw new MediaException('This type of file can\'t be uploaded. ' . $this->types->summary());
        }
        $max = $this->limits->maxBytes($format->kind);
        if ($size > $max) {
            throw new MediaException(sprintf(
                'The file is %s; %s can be at most %s.',
                MediaLimits::describe($size),
                self::plural($format->kind),
                MediaLimits::describe($max),
            ));
        }

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
                $image = $this->images->process($localFile, $format);
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

    private static function plural(MediaKind $kind): string
    {
        return match ($kind) {
            MediaKind::Image => 'pictures',
            MediaKind::Video => 'videos',
            MediaKind::Audio => 'audio files',
            MediaKind::Document => 'documents',
        };
    }
}
