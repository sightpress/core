<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

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

    private const int QUALITY = 85;

    public function __construct(
        private ImageManagerInterface $images,
        private MediaLimits $limits,
    ) {
    }

    public function process(string $localFile, MediaFormat $format): ProcessedImage
    {
        $size = @getimagesize($localFile);
        if ($size === false) {
            throw new MediaException('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');
        }
        if ($size[0] * $size[1] > $this->limits->imagePixels) {
            throw new MediaException(sprintf(
                'This picture is %d × %d pixels, which is more than %s million pixels. Make it smaller and upload it again.',
                $size[0],
                $size[1],
                number_format($this->limits->imagePixels / 1_000_000, 0, '.', ','),
            ));
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
