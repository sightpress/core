<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Media;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;

/**
 * Uploads through a real site container: the database is the site's, and the files land in the site's own folder
 * under `sites/<site id>/media/`, cleaned of metadata.
 */
#[CoversNothing]
final class MediaUploadTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('media');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAPictureIsStoredInTheSiteFolderWithItsCopies(): void
    {
        $source = $this->site->project->directory->write('upload/photo.jpg', MediaFixture::jpeg(1300, 650, exif: true));

        $media = Services::get($this->site->container(), MediaUploader::class)->upload($source, 'Lighthouse.jpg', 1)->media;

        $folder = $this->site->project->path() . '/sites/' . $this->site->site()->id . '/media/' . substr($media->key(), 0, 2);
        $stored = glob($folder . '/*');
        self::assertIsArray($stored);
        $expected = array_map(static fn (string $suffix): string => $folder . '/' . $media->key() . $suffix . '.jpg', ['', '-320', '-640', '-960', '-1280']);
        sort($expected);
        sort($stored);
        self::assertSame($expected, $stored);
        self::assertStringNotContainsString('SightPressCam', (string) file_get_contents($folder . '/' . $media->key() . '.jpg'));

        Services::get($this->site->container(), EntityManagerInterface::class)->clear();
        $found = Services::get($this->site->container(), MediaRepository::class)->findByKey($media->key());
        self::assertNotNull($found);
        self::assertSame(['lighthouse.jpg', [320, 640, 960, 1280], false], [$found->fileName(), $found->variantWidths(), $found->isPublished()]);
    }

    public function testARefusedUploadLeavesNothingBehind(): void
    {
        $source = $this->site->project->directory->write('upload/page.html', '<!doctype html><html><script>alert(1)</script></html>');

        try {
            Services::get($this->site->container(), MediaUploader::class)->upload($source, 'page.jpg', 1);
            self::fail('HTML must be refused.');
        } catch (MediaException $exception) {
            self::assertStringStartsWith('This type of file can\'t be uploaded.', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->site->project->path() . '/sites/' . $this->site->site()->id);
    }
}
