<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use SightPress\Core\Tests\Support\Text;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUploaded;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Media\UploadResult;
use SightPress\Core\Tests\Support\FakeIncomingFile;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(MediaUploader::class)]
#[CoversClass(UploadResult::class)]
#[CoversClass(MediaUploaded::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaUploaderTest extends TestCase
{
    private MediaFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture(new MediaLimits(documentBytes: 2048));
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testStoresAPictureCleanedWithItsCopiesAndAnnouncesIt(): void
    {
        $jpeg = MediaFixture::jpeg(700, 350, exif: true);
        $result = $this->fixture->uploader()->upload($this->fixture->file('in.jpg', $jpeg), 'Férias no Algarve.JPG', 7);

        $media = $result->media;
        self::assertFalse($result->duplicate);
        self::assertNotNull($media->id());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $media->key());
        self::assertSame(hash('sha256', $jpeg), $media->checksum());
        self::assertSame([MediaKind::Image, 'image/jpeg', 'ferias-no-algarve.jpg', 'Férias no Algarve.JPG'], Text::of([$media->kind(), $media->mimeType(), $media->fileName(), $media->originalName()]));
        self::assertSame([700, 350, [320, 640]], Text::of([$media->width(), $media->height(), $media->variantWidths()]));
        self::assertFalse($media->isPublished());
        self::assertSame(7, $media->uploadedBy());
        self::assertSame($this->fixture->clock->now(), $media->createdAt());

        $key = $media->key();
        self::assertSame([MediaFiles::path($key, 'jpg', 320), MediaFiles::path($key, 'jpg', 640), MediaFiles::path($key, 'jpg')], Text::of($this->fixture->stored()));
        $stored = $this->fixture->storage->read(MediaFiles::path($key, 'jpg'));
        self::assertSame(strlen($stored), $media->size());
        self::assertStringNotContainsString('SightPressCam', $stored);

        self::assertEquals([new MediaUploaded($media->id(), $key, MediaKind::Image, 7)], Text::of($this->fixture->events));
        self::assertSame($media, $this->fixture->repository()->findByKey($key));
    }

    public function testStoresOtherFilesAsTheyAre(): void
    {
        $csv = "name,points\nAda,10\n";

        $media = $this->fixture->uploader()->upload($this->fixture->file('upload.tmp', $csv), 'Points.csv', null)->media;

        self::assertSame([MediaKind::Document, 'text/csv', 'points.csv', strlen($csv), null, []], Text::of([$media->kind(), $media->mimeType(), $media->fileName(), $media->size(), $media->width(), $media->variantWidths()]));
        self::assertSame($csv, $this->fixture->storage->read(MediaFiles::path($media->key(), 'csv')));
    }

    public function testAcceptsOnlyRealWebVttCaptions(): void
    {
        $uploader = $this->fixture->uploader();

        $captions = $uploader->upload($this->fixture->file('a.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello\n"), 'Captions EN.vtt', 1)->media;
        self::assertSame(['text/vtt', 'captions-en.vtt'], Text::of([$captions->mimeType(), $captions->fileName()]));
        self::assertSame('text/vtt', Text::of($uploader->upload($this->fixture->file('b.vtt', "\xEF\xBB\xBFWEBVTT - With a title\n"), 'b.vtt', 1)->media->mimeType()));
        self::assertSame('text/vtt', Text::of($uploader->upload($this->fixture->file('c.vtt', 'WEBVTT'), 'c.vtt', 1)->media->mimeType()));

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('This captions file isn\'t in WebVTT format: its first line must be "WEBVTT".');
        $uploader->upload($this->fixture->file('d.vtt', "Hello, these are my notes.\n"), 'subtitles.vtt', 1);
    }

    public function testTheSameFileIsStoredOnce(): void
    {
        $uploader = $this->fixture->uploader();
        $first = $uploader->upload($this->fixture->file('a.txt', 'hello'), 'a.txt', 1);

        $second = $uploader->upload($this->fixture->file('b.txt', 'hello'), 'b.txt', 2);

        self::assertTrue($second->duplicate);
        self::assertSame($first->media, $second->media);
        self::assertCount(1, $this->fixture->stored());
        self::assertCount(1, $this->fixture->events);
    }

    public function testRefusesFilesByTheirRealContent(): void
    {
        $cases = [
            'This type of file can\'t be uploaded. You can upload pictures' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'],
            'This type of file can\'t be uploaded. ' => ['<?php system($_GET["c"]);', 'photo.jpg'],
            'The file is 2.9 KB; documents can be at most 2 KB.' => [str_repeat('a', 3000), 'big.txt'],
            'The file is empty. Choose another file.' => ['', 'empty.txt'],
            'This picture can\'t be read.' => [substr(MediaFixture::png(100, 100), 0, 40), 'broken.png'],
        ];
        foreach ($cases as $message => [$contents, $name]) {
            try {
                $this->fixture->uploader()->upload($this->fixture->file('upload.bin', $contents), $name, null);
                self::fail($message);
            } catch (MediaException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
        }
        self::assertSame([], Text::of($this->fixture->stored()));
        self::assertSame([], Text::of($this->fixture->events));
    }

    public function testRefusesAFileThatIsNotThere(): void
    {
        $this->expectExceptionMessage('The uploaded file can\'t be read. Try uploading it again.');

        $this->fixture->uploader()->upload($this->fixture->directory->path . '/missing', 'a.txt', null);
    }

    public function testNamesTheLimitOfEachKind(): void
    {
        $fixture = new MediaFixture(new MediaLimits(imageBytes: 10, videoBytes: 10, audioBytes: 10));
        try {
            foreach (['pictures' => MediaFixture::gif(), 'videos' => self::mp4(), 'audio files' => self::wav()] as $what => $contents) {
                try {
                    $fixture->uploader()->upload($fixture->file('upload.bin', $contents), 'file', null);
                    self::fail($what);
                } catch (MediaException $exception) {
                    self::assertStringContainsString('; ' . $what . ' can be at most 10 bytes.', $exception->getMessage());
                }
            }
        } finally {
            $fixture->remove();
        }
    }

    public function testRemovesStoredFilesWhenTheEntryCannotBeSaved(): void
    {
        $this->fixture->database->connection->createSchemaManager()->dropTable('media');

        try {
            $this->fixture->uploader()->upload($this->fixture->file('in.png', MediaFixture::png(400, 100)), 'in.png', null);
            self::fail('The upload should fail.');
        } catch (\Throwable $exception) {
            self::assertNotInstanceOf(MediaException::class, $exception);
        }

        self::assertSame([], Text::of($this->fixture->stored()));
    }

    public function testTakesFilesSentWithAForm(): void
    {
        $before = count((array) glob(sys_get_temp_dir() . '/sp-upload-*'));
        $upload = new UploadedFile(Stream::create('hello form'), 10, UPLOAD_ERR_OK, 'notes.txt', 'text/html');

        $media = $this->fixture->uploader()->uploadFile($upload, 3)->media;

        self::assertSame(['text/plain', 'notes.txt', 3], Text::of([$media->mimeType(), $media->fileName(), $media->uploadedBy()]));
        self::assertCount($before, (array) glob(sys_get_temp_dir() . '/sp-upload-*'));
    }

    public function testExplainsUploadErrors(): void
    {
        $cases = [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than this server accepts.',
            UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL => 'Only part of the file arrived.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server couldn\'t store the upload.',
        ];
        foreach ($cases as $error => $message) {
            try {
                $this->fixture->uploader()->uploadFile(new UploadedFile(Stream::create(''), 0, $error, 'a.txt'), null);
                self::fail($message);
            } catch (MediaException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
        }
    }

    public function testCleansUpTheTemporaryCopyWhenMovingFails(): void
    {
        $upload = self::createStub(\Psr\Http\Message\UploadedFileInterface::class);
        $upload->method('getError')->willReturn(UPLOAD_ERR_OK);
        $upload->method('moveTo')->willThrowException(new RuntimeException('disk full'));

        $this->expectExceptionMessage('disk full');

        $this->fixture->uploader()->uploadFile($upload, null);
    }

    public function testAnEmptyFileInTheBucketIsRefused(): void
    {
        $file = new FakeIncomingFile('');

        try {
            $this->fixture->uploader()->adopt($file, 'empty.csv', 7);
            self::fail('An empty file must be refused.');
        } catch (MediaException $exception) {
            self::assertSame('The file is empty. Choose another file.', $exception->getMessage());
        }
        self::assertFalse($file->deleted, 'the caller deletes a refused file');
    }

    public function testACopyWhoseRowCantBeSavedIsRemoved(): void
    {
        $storage = $this->fixture->storage;
        $entities = $this->fixture->database->entityManager;
        $file = new FakeIncomingFile("name,points\nAda,10\n", static function (string $path, string $contents) use ($storage, $entities): void {
            $storage->write($path, $contents);
            $entities->close();
        });

        try {
            $this->fixture->uploader()->adopt($file, 'points.csv', 7);
            self::fail('The row can\'t be saved.');
        } catch (EntityManagerClosed) {
            self::assertSame([], $this->fixture->stored());
            self::assertFalse($file->deleted, 'the incoming file stays, so the check can run again');
        }
    }

    /**
     * The start of an MP4 file: enough for finfo.
     */
    private static function mp4(): string
    {
        return pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . str_repeat("\0", 32);
    }

    /**
     * A WAV file with a second of silence at 8 kHz.
     */
    private static function wav(): string
    {
        $data = str_repeat("\x80", 8000);

        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', strlen($data)) . $data;
    }

    public function testRefusesACaptionsFileWithAMistakeAndSaysOnWhichLine(): void
    {
        try {
            $this->fixture->uploader()->upload($this->fixture->file('bad.vtt', "WEBVTT\n\n00:05.000 --> 00:01.000\nHello\n"), 'bad.vtt', 1);
            self::fail('The file was accepted.');
        } catch (MediaException $refused) {
            self::assertSame('This captions file has a mistake on line 3: the cue must end after it starts.', $refused->getMessage());
        }
        self::assertSame([], $this->fixture->stored());
    }

    public function testChecksACaptionsFileSentStraightToTheBucket(): void
    {
        $good = new FakeIncomingFile("WEBVTT\n\n00:01.000 --> 00:02.000\nHello\n");
        self::assertSame('text/vtt', $this->fixture->uploader()->adopt($good, 'good.vtt', 7)->media->mimeType());

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('mistake on line 3');
        $this->fixture->uploader()->adopt(new FakeIncomingFile("WEBVTT\n\nnot a time --> at all\nHello\n"), 'bad.vtt', 7);
    }
}
