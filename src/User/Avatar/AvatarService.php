<?php

declare(strict_types=1);

namespace SightPress\Core\User\Avatar;

use SightPress\Core\Template\Message;
use finfo;
use Intervention\Image\Format;
use Intervention\Image\Interfaces\ExceptionInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;
use SightPress\Core\Http\Upload\UploadLimits;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Entity\User;

/**
 * Looks after avatars, in the site's storage under `avatars/` (not in the media library), with random file names
 * that change with every new picture, so they can be cached for a year.
 *
 * - **Generated**: an SVG of the initials ({@see AvatarGenerator}), made when the account is created and made again
 *   when the name changes.
 * - **Uploaded**: a JPEG, PNG or WebP picture. It is checked on its real content (an SVG or a file that only claims to
 *   be a picture is refused), cropped to a square from the middle, re-encoded without metadata (camera, GPS
 *   position …) and stored at {@see SIZES}. Removing it goes back to the generated avatar.
 *
 * The caller saves the user afterwards.
 */
final readonly class AvatarService
{
    /** Square sizes of an uploaded picture, in pixels; the largest one is the stored picture. */
    public const array SIZES = [64, 128, 256];

    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_PIXELS = 40_000_000;

    /** @var array<string, array{Format, string}> allowed MIME type => format and file extension */
    private const array TYPES = [
        'image/jpeg' => [Format::JPEG, 'jpg'],
        'image/png' => [Format::PNG, 'png'],
        'image/webp' => [Format::WEBP, 'webp'],
    ];

    private const string DIRECTORY = 'avatars/';

    private const string STORE_FAILED = 'The server couldn\'t store the picture. Try again later.';

    public function __construct(
        private AvatarGenerator $generator,
        private ImageManagerInterface $images,
        private FilesystemOperator $storage,
        private ClockInterface $clock,
        private UploadLimits $limits,
    ) {
    }

    /**
     * Gives the user a new generated avatar, with their colour (picked once, from their id) and current initials.
     * An uploaded picture is replaced too. The user must have been saved, so they have an id.
     */
    public function generate(User $user): void
    {
        $id = $user->id() ?? throw new \LogicException('Save the user before making their avatar: the colour comes from their id.');
        $color = $user->avatarColor() ?? $this->generator->colorFor($id);
        $path = self::DIRECTORY . bin2hex(random_bytes(16)) . '.svg';
        $this->write($path, $this->generator->svg($this->generator->initials($user->firstName(), $user->lastName(), $user->locale()), $color));
        $old = $this->files($user);
        $user->setAvatar(AvatarKind::Generated, $path, $color, $this->clock->now());
        $this->delete($old);
    }

    /**
     * After a name change: makes the generated avatar again with the new initials. An uploaded picture stays.
     */
    public function nameChanged(User $user): void
    {
        if ($user->avatarKind() !== AvatarKind::Uploaded) {
            $this->generate($user);
        }
    }

    /**
     * @throws AvatarException when the upload is missing, too large, not a JPEG, PNG or WebP picture, or damaged
     */
    public function upload(User $user, UploadedFileInterface $upload): void
    {
        $error = $upload->getError();
        $max = $this->maxBytes();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $upload->getSize() > $max) {
            throw new AvatarException(new Message('The picture can be at most {max}.', ['max' => $this->limits->describe($max)]));
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new AvatarException('Choose a picture to upload.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new AvatarException('The picture didn\'t arrive. Try uploading it again.');
        }

        $local = tempnam(sys_get_temp_dir(), 'sp-avatar-');
        $local = is_string($local) ? $local : throw new AvatarException(self::STORE_FAILED);
        try {
            $upload->moveTo($local);
            $this->store($user, $local);
        } finally {
            if (is_file($local)) {
                unlink($local);
            }
        }
    }

    /**
     * The largest picture someone can upload as their avatar on this site.
     *
     * @return positive-int
     */
    public function maxBytes(): int
    {
        return $this->limits->pictureLimit(self::MAX_BYTES);
    }

    /**
     * The largest picture, in words.
     */
    public function maxSize(): string
    {
        return $this->limits->describe($this->maxBytes());
    }

    /**
     * Removes an uploaded picture and goes back to the generated avatar.
     */
    public function remove(User $user): void
    {
        if ($user->avatarKind() === AvatarKind::Uploaded) {
            $this->generate($user);
        }
    }

    /**
     * Address of the avatar at a size (an SVG is the same file at every size), or null when the user has none.
     */
    public static function url(User $user, int $size = 128): ?string
    {
        $path = $user->avatarPath();
        if ($path === null) {
            return null;
        }
        $file = substr($path, strlen(self::DIRECTORY));
        if ($user->avatarKind() !== AvatarKind::Uploaded) {
            return '/avatars/' . $file;
        }
        $size = in_array($size, self::SIZES, true) ? $size : self::SIZES[count(self::SIZES) - 1];
        [$name, $extension] = explode('.', $file, 2);

        return sprintf('/avatars/%s-%d.%s', $name, $size, $extension);
    }

    private function store(User $user, string $local): void
    {
        $type = new finfo(FILEINFO_MIME_TYPE)->file($local);
        if ($type === 'image/svg+xml') {
            throw new AvatarException('SVG pictures can\'t be used as a profile picture. Upload a JPEG, PNG or WebP picture.');
        }
        if (!is_string($type) || !isset(self::TYPES[$type])) {
            throw new AvatarException('The file must be a JPEG, PNG or WebP picture.');
        }
        $size = @getimagesize($local);
        if ($size === false) {
            throw new AvatarException('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new AvatarException('This picture has too many pixels. Make it smaller and upload it again.');
        }

        [$format, $extension] = self::TYPES[$type];
        $name = bin2hex(random_bytes(16));
        $sizes = self::SIZES;
        rsort($sizes);
        $encoded = [];
        try {
            $image = $this->images->decodePath($local)->cover($sizes[0], $sizes[0]);
            foreach ($sizes as $pixels) {
                $encoded[$pixels] = (string) $image->resize($pixels, $pixels)->encodeUsingFormat($format);
            }
        } catch (ExceptionInterface) {
            throw new AvatarException('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');
        }
        foreach ($encoded as $pixels => $contents) {
            $this->write(sprintf('%s%s-%d.%s', self::DIRECTORY, $name, $pixels, $extension), $contents);
        }

        $old = $this->files($user);
        $user->setAvatar(AvatarKind::Uploaded, self::DIRECTORY . $name . '.' . $extension, $user->avatarColor() ?? $this->generator->colorFor((int) $user->id()), $this->clock->now());
        $this->delete($old);
    }

    /**
     * @return list<string> the stored files of the user's current avatar
     */
    private function files(User $user): array
    {
        $path = $user->avatarPath();
        if ($path === null) {
            return [];
        }
        if ($user->avatarKind() !== AvatarKind::Uploaded) {
            return [$path];
        }
        [$name, $extension] = explode('.', $path, 2);

        return array_map(static fn (int $size): string => sprintf('%s-%d.%s', $name, $size, $extension), self::SIZES);
    }

    private function write(string $path, string $contents): void
    {
        try {
            $this->storage->write($path, $contents);
        } catch (FilesystemException) {
            throw new AvatarException(self::STORE_FAILED);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function delete(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $this->storage->delete($path);
            } catch (FilesystemException) {
                // A file that can't be removed is only wasted space; the account already points at the new avatar.
            }
        }
    }
}
