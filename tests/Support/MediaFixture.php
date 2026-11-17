<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Media\ImageProcessor;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\MediaUploader;

/**
 * A media library for tests: a migrated site database in memory, storage in a temporary folder, a fixed clock and
 * a dispatcher that keeps the events. Also makes real pictures with GD.
 */
final class MediaFixture implements EventDispatcherInterface
{
    public readonly TemporaryDirectory $directory;

    public readonly CoreSiteDatabase $database;

    public readonly Filesystem $storage;

    public readonly FixedClock $clock;

    /** @var list<object> */
    public array $events = [];

    public function __construct(public readonly MediaLimits $limits = new MediaLimits(), ?CoreSiteDatabase $database = null)
    {
        $this->directory = new TemporaryDirectory();
        $this->database = $database ?? new CoreSiteDatabase();
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->directory->path . '/site', lazyRootCreation: true));
        $this->clock = new FixedClock();
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function images(): ImageProcessor
    {
        return new ImageProcessor(new ImageManager(Driver::class, autoOrientation: true, strip: true), $this->limits);
    }

    public function files(): MediaFiles
    {
        return new MediaFiles($this->storage);
    }

    public function repository(): MediaRepository
    {
        return new MediaRepository($this->database->entityManager);
    }

    public function uploader(): MediaUploader
    {
        return new MediaUploader(new MediaTypes(), $this->limits, $this->images(), $this->files(), $this->repository(), $this->clock, $this);
    }

    /**
     * Writes a file to the temporary folder and returns its path.
     */
    public function file(string $name, string $contents): string
    {
        return $this->directory->write('uploads/' . $name, $contents);
    }

    /**
     * Paths of everything stored in the site's storage, sorted.
     *
     * @return list<string>
     */
    public function stored(): array
    {
        $paths = [];
        foreach ($this->storage->listContents('', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }
        sort($paths);

        return $paths;
    }

    public function remove(): void
    {
        $this->directory->remove();
    }

    /**
     * A JPEG of the given size; with `$exif`, an EXIF block (camera make "SightPressCam" and the orientation)
     * right after the start marker, as cameras write it.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function jpeg(int $width = 800, int $height = 600, bool $exif = false, int $orientation = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        return $exif ? substr($jpeg, 0, 2) . self::exifSegment($orientation) . substr($jpeg, 2) : $jpeg;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function png(int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function gif(int $width = 100, int $height = 50): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function webp(int $width = 100, int $height = 50): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG APP1 segment with a big-endian TIFF header and two IFD0 entries: Make (0x010F) and Orientation (0x0112).
     */
    private static function exifSegment(int $orientation): string
    {
        $make = "SightPressCam\0";
        $entries = 2;
        $ifdSize = 2 + $entries * 12 + 4;
        $makeOffset = 8 + $ifdSize;
        $tiff = 'MM' . pack('n', 42) . pack('N', 8)
            . pack('n', $entries)
            . pack('nnN', 0x010F, 2, strlen($make)) . pack('N', $makeOffset)
            . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\0\0"
            . pack('N', 0)
            . $make;
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }
}
