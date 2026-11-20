<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Preview\PreviewFolder;
use SightPress\Core\Theme\Preview\StaticPage;
use SightPress\Core\Theme\ThemeException;

#[CoversClass(PreviewFolder::class)]
#[UsesClass(StaticPage::class)]
#[UsesClass(ThemeException::class)]
final class PreviewFolderTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testWritesTheFilesAndReplacesAnEarlierPreview(): void
    {
        $out = $this->directory->path . '/out';
        $folder = new PreviewFolder($out);
        $folder->write(['/' => 'page', '/assets/css/site.css' => 'css', '/old.txt' => 'old']);
        symlink($this->directory->path, $out . '/link');
        $folder->write(['/' => 'new page', '/media/a/b.jpg' => 'picture']);

        self::assertSame('new page', file_get_contents($out . '/index.html'));
        self::assertSame('picture', file_get_contents($out . '/media/a/b.jpg'));
        self::assertFileDoesNotExist($out . '/old.txt');
        self::assertDirectoryDoesNotExist($out . '/assets');
        self::assertFileDoesNotExist($out . '/link');
        self::assertDirectoryExists($this->directory->path);
    }

    public function testWritesIntoAnEmptyFolder(): void
    {
        mkdir($this->directory->path . '/empty');
        new PreviewFolder($this->directory->path . '/empty')->write(['/' => 'page']);

        self::assertFileExists($this->directory->path . '/empty/index.html');
    }

    public function testNeverDeletesAFolderThatHoldsSomethingElse(): void
    {
        $this->directory->write('mine/notes.txt', 'keep me');

        try {
            new PreviewFolder($this->directory->path . '/mine')->write(['/' => 'page']);
            self::fail('The folder was replaced.');
        } catch (ThemeException $exception) {
            self::assertStringContainsString('is not empty and holds no earlier preview', $exception->getMessage());
        }
        self::assertFileExists($this->directory->path . '/mine/notes.txt');
    }

    public function testSaysWhenAFileCantBeWritten(): void
    {
        $this->directory->write('file', 'not a folder');

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('The preview could not be written to ' . $this->directory->path . '/file/out/index.html.');
        new PreviewFolder($this->directory->path . '/file/out')->write(['/' => 'page']);
    }

    public function testRemovingAFolderThatIsNotThereDoesNothing(): void
    {
        PreviewFolder::remove($this->directory->path . '/nowhere');

        self::assertDirectoryExists($this->directory->path);
    }
}
