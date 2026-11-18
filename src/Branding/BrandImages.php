<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use SightPress\Core\Template\Message;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ExceptionInterface;

/**
 * Checks uploaded branding pictures and makes the files browsers and social networks ask for: the icon set (PNG at
 * 32, 180, 192 and 512 pixels and a `favicon.ico`), the share image (1200 × 630) and, when nothing was uploaded,
 * pictures with the site's initials or name in {@see BrandColours}. Uploads are decoded and encoded again, which
 * drops anything that isn't the picture itself (metadata, trailing data).
 */
final readonly class BrandImages
{
    public const int ICON_MIN = 512;

    public const int SHARE_WIDTH = 1200;

    public const int SHARE_HEIGHT = 630;

    /** Icon sizes in pixels, by file name. */
    public const array ICON_FILES = ['icon-32.png' => 32, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512];

    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const int MAX_PIXELS = 40_000_000;

    private const array RASTER_TYPES = ['image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP'];

    private ImageManager $images;

    private string $font;

    public function __construct()
    {
        $this->images = new ImageManager(new Driver(), autoOrientation: true, strip: true);
        $this->font = dirname(__DIR__, 2) . '/resources/fonts/DejaVuSans-Bold.ttf';
    }

    /**
     * The MIME type of an uploaded file, as `finfo` reads it from the contents.
     */
    public static function mimeType(string $bytes): string
    {
        return (string) new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
    }

    /**
     * Checks a raster picture and gives its width and height.
     *
     * @return array{int, int}
     */
    public function size(string $bytes, BrandAsset $asset): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new BrandingException(new Message('The {label} must be 5 MB or smaller.', ['label' => Message::of($asset->label())->lowerFirst()]));
        }
        if (!isset(self::RASTER_TYPES[self::mimeType($bytes)])) {
            throw new BrandingException(new Message('The {label} must be a {web_p} picture.', ['label' => Message::of($asset->label())->lowerFirst(), 'web_p' => $asset === BrandAsset::Logo ? 'SVG, PNG, JPEG or WebP' : 'PNG, JPEG or WebP']));
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new BrandingException(new Message('The {label} can\'t be read. It may be damaged; save it again and try the new file.', ['label' => Message::of($asset->label())->lowerFirst()]));
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new BrandingException(new Message('The {label} is {size} × {size2} pixels, which is too large. Make it smaller than 40 million pixels.', ['label' => Message::of($asset->label())->lowerFirst(), 'size' => (string) $size[0], 'size2' => (string) $size[1]]));
        }

        return [$size[0], $size[1]];
    }

    /**
     * An uploaded icon as the icon set; it must be square and at least {@see ICON_MIN} pixels wide.
     *
     * @return array<string, string> file name => contents, `favicon.ico` included
     */
    public function iconSet(string $bytes): array
    {
        [$width, $height] = $this->size($bytes, BrandAsset::Icon);
        if ($width !== $height || $width < self::ICON_MIN) {
            throw new BrandingException(new Message('The icon must be a square picture of at least {icon_min} × {icon_min} pixels. This one is {width} × {height} pixels.', ['icon_min' => (string) self::ICON_MIN, 'width' => (string) $width, 'height' => (string) $height]));
        }
        $files = [];
        foreach (self::ICON_FILES as $name => $pixels) {
            $files[$name] = $this->encode($bytes, static fn ($image) => $image->resize($pixels, $pixels), Format::PNG);
        }
        $files['favicon.ico'] = self::ico($files['icon-32.png'], 32);

        return $files;
    }

    /**
     * An uploaded share image at 1200 × 630, as a JPEG; it must be at least that size, in about the same
     * proportions.
     */
    public function shareImage(string $bytes): string
    {
        [$width, $height] = $this->size($bytes, BrandAsset::ShareImage);
        $ratio = self::SHARE_WIDTH / self::SHARE_HEIGHT;
        if ($width < self::SHARE_WIDTH || $height < self::SHARE_HEIGHT || abs($width / $height - $ratio) > $ratio * 0.02) {
            throw new BrandingException(new Message('The share image must be {share_width} × {share_height} pixels, or larger in the same proportions. This one is {width} × {height} pixels.', ['share_width' => (string) self::SHARE_WIDTH, 'share_height' => (string) self::SHARE_HEIGHT, 'width' => (string) $width, 'height' => (string) $height]));
        }

        return $this->encode($bytes, static fn ($image) => $image->cover(self::SHARE_WIDTH, self::SHARE_HEIGHT), Format::JPEG, ['quality' => 85]);
    }

    /**
     * An uploaded raster logo, re-encoded as PNG and at most 1000 pixels wide; from 1:1 to 8:1 wide.
     *
     * @return array{string, int, int} the PNG, its width and height
     */
    public function rasterLogo(string $bytes): array
    {
        [$width, $height] = $this->size($bytes, BrandAsset::Logo);
        self::checkLogoRatio($width, $height);
        if ($width > 1000) {
            [$width, $height] = [1000, max(1, (int) round($height * 1000 / $width))];
        }

        return [$this->encode($bytes, static fn ($image) => $image->resize($width, $height), Format::PNG), $width, $height];
    }

    public static function checkLogoRatio(float $width, float $height): void
    {
        $ratio = $width / $height;
        if ($ratio < 1 || $ratio > 8) {
            throw new BrandingException(new Message('The logo must be at least as wide as it is high, and at most 8 times as wide. This one is {width} × {height}.', ['width' => self::number($width), 'height' => self::number($height)]));
        }
    }

    /**
     * The generated icon set: the initials in white on {@see BrandColours::BACKGROUND}, and `icon.svg`.
     *
     * @return array<string, string>
     */
    public function initialsIconSet(string $siteName): array
    {
        $initials = Initials::of($siteName);
        $files = [];
        foreach (self::ICON_FILES as $name => $pixels) {
            $files[$name] = $this->draw($pixels, $pixels, Initials::drawable($initials) ? $initials : '', 0.42);
        }
        $files['favicon.ico'] = self::ico($files['icon-32.png'], 32);
        $files['icon.svg'] = self::initialsSvg($initials);

        return $files;
    }

    /**
     * The generated share image: the site's name in white on {@see BrandColours::BACKGROUND}, 1200 × 630 PNG.
     */
    public function generatedShareImage(string $siteName): string
    {
        $name = trim($siteName);

        return $this->draw(self::SHARE_WIDTH, self::SHARE_HEIGHT, Initials::drawable($name) ? $name : '', 0.11);
    }

    /**
     * The generated wordmark: the site's name in {@see BrandColours::BACKGROUND} on a transparent background.
     *
     * @return array{string, int, int} the SVG, its width and height
     */
    public static function wordmark(string $siteName): array
    {
        $name = htmlspecialchars(trim($siteName), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $width = max(64, 24 + (int) ceil(mb_strlen(trim($siteName)) * 22));

        return [sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="64" viewBox="0 0 %1$d 64" role="img" aria-label="%2$s">'
            . '<title>%2$s</title><text x="12" y="42" fill="%3$s" font-family="system-ui, -apple-system, \'Segoe UI\', Roboto, \'Noto Sans\', sans-serif"'
            . ' font-size="36" font-weight="700">%2$s</text></svg>',
            $width,
            $name,
            BrandColours::BACKGROUND,
        ), $width, 64];
    }

    /**
     * A `.ico` file holding one PNG (allowed since Windows Vista and read by every current browser).
     */
    public static function ico(string $png, int $pixels): string
    {
        return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $pixels % 256, $pixels % 256, 0, 0, 1, 32, strlen($png), 22) . $png;
    }

    private static function initialsSvg(string $initials): string
    {
        $text = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
            . '<rect width="512" height="512" rx="96" fill="' . BrandColours::BACKGROUND . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="' . BrandColours::FOREGROUND . '"'
            . ' font-family="system-ui, -apple-system, \'Segoe UI\', Roboto, \'Noto Sans\', sans-serif"'
            . ' font-size="' . (mb_strlen($initials) > 1 ? 220 : 280) . '" font-weight="700">' . $text . '</text></svg>';
    }

    /**
     * @param callable(\Intervention\Image\Interfaces\ImageInterface): \Intervention\Image\Interfaces\ImageInterface $change
     * @param array<string, int>                                                                                      $options
     */
    private function encode(string $bytes, callable $change, Format $format, array $options = []): string
    {
        try {
            return (string) $change($this->images->decodeBinary($bytes))->encodeUsingFormat($format, ...$options);
        } catch (ExceptionInterface) {
            throw new BrandingException('This picture can\'t be read. It may be damaged; save it again and try the new file.');
        }
    }

    /**
     * Text in white, centred (wrapped onto up to three lines), on the brand colour; `$scale` is the font size as a
     * share of the height.
     */
    private function draw(int $width, int $height, string $text, float $scale): string
    {
        $image = self::canvas(imagecreatetruecolor(max(1, $width), max(1, $height)));
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...BrandColours::rgb(BrandColours::BACKGROUND)));
        $colour = (int) imagecolorallocate($image, ...BrandColours::rgb(BrandColours::FOREGROUND));
        $size = $height * $scale;
        $lines = $this->wrap($text, $size, $width * 0.84);
        $lineHeight = $size * 1.3;
        $top = ($height - $lineHeight * count($lines)) / 2 + $size;
        foreach ($lines as $index => $line) {
            $x = ($width - $this->textWidth($line, $size)) / 2;
            imagettftext($image, $size, 0, (int) round($x), (int) round($top + $index * $lineHeight), $colour, $this->font, $line);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return list<string> at most three lines; the last one ends with "…" when the text doesn't fit
     */
    private function wrap(string $text, float $size, float $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $line = '';
        foreach ($words === false ? [] : $words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > 3) {
            $lines = [...array_slice($lines, 0, 2), $lines[2] . '…'];
        }

        return $lines;
    }

    private function textWidth(string $text, float $size): int
    {
        $box = imagettfbbox($size, 0, $this->font, $text);
        [$left, $right] = [$box[0] ?? 0, $box[2] ?? 0];

        return (is_int($right) ? $right : 0) - (is_int($left) ? $left : 0);
    }

    private static function canvas(\GdImage|false $image): \GdImage
    {
        return $image instanceof \GdImage ? $image : throw new BrandingException('The picture could not be drawn.');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
