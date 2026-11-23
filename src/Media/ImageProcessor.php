<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Template\Message;
use Intervention\Image\Format;
use Intervention\Image\Interfaces\ExceptionInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;

/**
 * Re-encodes uploaded pictures and makes smaller copies of them.
 *
 * Re-encoding turns the picture the right way up (EXIF orientation) and then drops all metadata — camera, GPS
 * position, comments — and anything hidden after the image data. Copies are made at {@see WIDTHS} that are
 * narrower than the picture, in the same format. The pixel limit is checked from the file header before the picture
 * is decoded, so an oversized picture is refused without being loaded into memory.
 */
final readonly class ImageProcessor
{
    /** Widths of the smaller copies, in pixels. */
    public const array WIDTHS = [320, 640, 960, 1280, 1920];

    /** Memory one pixel takes while a picture is decoded and its copies are made: 25 million pixels fit in 256 MB. */
    public const int BYTES_PER_PIXEL = 11;

    private const int QUALITY = 85;

    public function __construct(
        private ImageManagerInterface $images,
        private MediaLimits $limits,
    ) {
    }

    /**
     * @param ?int $importPixels the command line's own pixel limit (it can raise it for one run); null for uploads
     */
    public function process(string $localFile, MediaFormat $format, ?int $importPixels = null): ProcessedImage
    {
        $size = @getimagesize($localFile);
        if ($size === false) {
            throw new MediaException('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');
        }
        if ($importPixels !== null && $size[0] * $size[1] > $importPixels) {
            $memory = (int) ceil($size[0] * $size[1] * self::BYTES_PER_PIXEL / 1_048_576);

            throw new MediaException(new Message('This picture is {width} × {height} pixels and needs about {memory} MB of memory. Run the import again with --memory-limit={memory}M.', ['width' => (string) $size[0], 'height' => (string) $size[1], 'memory' => (string) $memory]));
        }
        if ($importPixels === null && $size[0] * $size[1] > $this->limits->imagePixels) {
            throw new MediaException(new Message('This picture is {size} × {size2} pixels, which is more than {_000_000} million pixels. Make it smaller and upload it again.', ['size' => (string) $size[0], 'size2' => (string) $size[1], '_000_000' => number_format($this->limits->imagePixels / 1_000_000, 0, '.', ',')]));
        }

        $target = match ($format->extension) {
            'png' => Format::PNG,
            'webp' => Format::WEBP,
            'gif' => Format::GIF,
            default => Format::JPEG,
        };
        $options = $target === Format::JPEG || $target === Format::WEBP ? ['quality' => self::QUALITY] : [];

        try {
            $image = $this->images->decodePath($localFile);
            $original = (string) $image->encodeUsingFormat($target, ...$options);
            $width = $image->width();
            $height = $image->height();
            $variants = [];
            foreach (array_reverse(self::WIDTHS) as $variantWidth) {
                if ($variantWidth < $width) {
                    $variants[$variantWidth] = (string) $image->scaleDown(width: $variantWidth)->encodeUsingFormat($target, ...$options);
                }
            }
        } catch (ExceptionInterface) {
            throw new MediaException('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');
        }
        ksort($variants);

        return new ProcessedImage($original, $width, $height, $variants);
    }
}
