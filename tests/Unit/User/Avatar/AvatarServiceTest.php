<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User\Avatar;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\User\Avatar\AvatarException;
use SightPress\Core\User\Avatar\AvatarGenerator;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Entity\User;

#[CoversClass(AvatarService::class)]
#[CoversClass(AvatarException::class)]
#[UsesNamespace('SightPress\Core')]
final class AvatarServiceTest extends TestCase
{
    private AuthFixture $auth;

    private AvatarService $avatars;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->avatars = $this->auth->avatars();
    }

    public function testGeneratesTheInitialsWithAColourThatStays(): void
    {
        $user = $this->auth->user();
        $id = (int) $user->id();

        $this->avatars->generate($user);
        $first = (string) $user->avatarPath();

        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.svg$#', $first);
        self::assertSame(new AvatarGenerator()->colorFor($id), $user->avatarColor());
        self::assertStringContainsString('>AL</text>', $this->auth->storage()->read($first));
        self::assertSame('/avatars/' . substr($first, 8), AvatarService::url($user, 64));

        $user->rename('Grace', 'Hopper', null, $this->auth->clock->now());
        $this->avatars->nameChanged($user);

        self::assertNotSame($first, $user->avatarPath());
        self::assertStringContainsString('>GH</text>', $this->auth->storage()->read((string) $user->avatarPath()));
        self::assertSame([(string) $user->avatarPath()], $this->auth->stored(), 'the old file is gone');
    }

    public function testAUserNeedsAnIdForTheirColour(): void
    {
        $this->expectException(\LogicException::class);

        $this->avatars->generate(new User('new@example.org', 'New', 'Person', 'UTC', $this->auth->clock->now()));
    }

    public function testUploadsASquarePictureAtEverySizeAndGoesBackToTheInitialsWhenRemoved(): void
    {
        $user = $this->auth->user();
        $this->avatars->generate($user);
        $color = $user->avatarColor();

        $this->avatars->upload($user, self::upload(MediaFixture::jpeg(800, 600, exif: true)));
        $path = (string) $user->avatarPath();
        [$name] = explode('.', $path);

        self::assertSame(AvatarKind::Uploaded, $user->avatarKind());
        self::assertSame($color, $user->avatarColor());
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.jpg$#', $path);
        self::assertSame([$name . '-128.jpg', $name . '-256.jpg', $name . '-64.jpg'], $this->auth->stored());
        foreach (AvatarService::SIZES as $size) {
            $stored = $this->auth->storage()->read(sprintf('%s-%d.jpg', $name, $size));
            self::assertSame([$size, $size], array_slice((array) getimagesizefromstring($stored), 0, 2));
            self::assertStringNotContainsString('SightPressCam', $stored, 'metadata is removed');
        }
        self::assertSame('/avatars/' . substr($name, 8) . '-64.jpg', AvatarService::url($user, 64));
        self::assertSame('/avatars/' . substr($name, 8) . '-256.jpg', AvatarService::url($user, 100), 'unknown sizes get the largest');

        $user->rename('Grace', 'Hopper', null, $this->auth->clock->now());
        $this->avatars->nameChanged($user);
        self::assertSame($path, $user->avatarPath(), 'an uploaded picture stays when the name changes');

        $this->avatars->remove($user);
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
        self::assertSame([(string) $user->avatarPath()], $this->auth->stored());
        $generated = $user->avatarPath();
        $this->avatars->remove($user);
        self::assertSame($generated, $user->avatarPath(), 'removing a generated avatar changes nothing');
    }

    public function testKeepsPngAndWebpPictures(): void
    {
        $user = $this->auth->user();

        $this->avatars->upload($user, self::upload(MediaFixture::png(300, 500)));
        self::assertStringEndsWith('.png', (string) $user->avatarPath());

        $image = imagecreatetruecolor(300, 300);
        ob_start();
        imagewebp($image);
        $this->avatars->upload($user, self::upload((string) ob_get_clean()));
        self::assertStringEndsWith('.webp', (string) $user->avatarPath());
        self::assertCount(3, $this->auth->stored(), 'the PNG files are gone');
    }

    public function testRefusesSvgFilesThatOnlyLookLikePicturesAndOtherFormats(): void
    {
        $user = $this->auth->user();
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $cases = [
            'SVG pictures can\'t be used as a profile picture. Upload a JPEG, PNG or WebP picture.' => self::upload($svg, 'me.svg'),
            'The file must be a JPEG, PNG or WebP picture.' => self::upload('<?php echo "hi";', 'me.png'),
            'The picture must be smaller than 5 MB.' => self::upload('x', 'big.jpg', UPLOAD_ERR_INI_SIZE),
            'Choose a picture to upload.' => self::upload('', 'none.jpg', UPLOAD_ERR_NO_FILE),
            'The picture didn\'t arrive. Try uploading it again.' => self::upload('x', 'half.jpg', UPLOAD_ERR_PARTIAL),
        ];
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $cases['The file must be a JPEG, PNG or WebP picture. '] = self::upload((string) ob_get_clean(), 'me.gif');

        foreach ($cases as $message => $upload) {
            try {
                $this->avatars->upload($user, $upload);
                self::fail('Accepted: ' . $message);
            } catch (AvatarException $exception) {
                self::assertSame(trim($message), $exception->getMessage());
            }
        }
        self::assertNull($user->avatarPath());
        self::assertSame([], $this->auth->stored());
    }

    public function testRefusesDamagedAndHugePictures(): void
    {
        $user = $this->auth->user();
        $jpeg = MediaFixture::jpeg(40, 40);
        $damaged = substr($jpeg, 0, 200) . str_repeat("\0", 200);
        $huge = MediaFixture::jpeg(10, 10);
        // Claim 10 000 × 10 000 pixels in the frame header (SOF0: FF C0, length, precision, height, width).
        $sof = strpos($huge, "\xFF\xC0");
        self::assertNotFalse($sof);
        $huge = substr_replace($huge, pack('n2', 10000, 10000), $sof + 5, 4);

        foreach ([
            'This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.' => $damaged,
            'This picture has too many pixels. Make it smaller and upload it again.' => $huge,
        ] as $message => $contents) {
            try {
                $this->avatars->upload($user, self::upload($contents));
                self::fail('Accepted: ' . $message);
            } catch (AvatarException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testAPictureThatIsUnreadableBeyondItsHeaderIsRefused(): void
    {
        $user = $this->auth->user();
        $jpeg = MediaFixture::jpeg(40, 40);
        $broken = substr($jpeg, 0, (int) (strlen($jpeg) * 0.4));

        $this->expectException(AvatarException::class);
        $this->expectExceptionMessage('This picture can\'t be read.');

        $this->avatars->upload($user, self::upload($broken));
    }

    public function testStorageFailuresAreExplainedAndOldFilesAreLeftBehindQuietly(): void
    {
        $user = $this->auth->user();
        $storage = self::createStub(FilesystemOperator::class);
        $storage->method('write')->willThrowException(UnableToWriteFile::atLocation('avatars/x.svg'));
        $broken = new AvatarService(new AvatarGenerator(), new ImageManager(Driver::class), $storage, $this->auth->clock);

        try {
            $broken->generate($user);
            self::fail('Stored nothing but said nothing.');
        } catch (AvatarException $exception) {
            self::assertSame('The server couldn\'t store the picture. Try again later.', $exception->getMessage());
        }

        $this->avatars->generate($user);
        $undeletable = self::createStub(FilesystemOperator::class);
        $undeletable->method('delete')->willThrowException(new \League\Flysystem\UnableToDeleteFile('avatars/x.svg'));
        new AvatarService(new AvatarGenerator(), new ImageManager(Driver::class), $undeletable, $this->auth->clock)->generate($user);
        self::assertSame(AvatarKind::Generated, $user->avatarKind());
    }

    public function testWithoutAnAvatarThereIsNoAddress(): void
    {
        self::assertNull(AvatarService::url($this->auth->user()));
    }

    private static function upload(string $contents, string $name = 'me.jpg', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), strlen($contents), $error, $name);
    }
}
