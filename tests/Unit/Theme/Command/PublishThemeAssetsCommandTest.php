<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\Command\PublishThemeAssetsCommand;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Core\Theme\PublishedPreviews;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;

#[CoversClass(PublishThemeAssetsCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class PublishThemeAssetsCommandTest extends TestCase
{
    private TemporaryDirectory $directory;

    private RecordingOutput $output;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->output = new RecordingOutput();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testPublishesThePreviewOfEveryInstalledTheme(): void
    {
        $command = $this->command([new HarbourTheme()], $this->node('touch "$2/screenshot.png"'));

        self::assertSame('theme:publish-assets', $command->name());
        self::assertNotSame('', $command->description());
        self::assertSame(['no-screenshot'], array_map(static fn ($p) => $p->name, $command->parameters()));
        self::assertSame(0, $command->run(new ArrayCommandInput(), $this->output), implode("\n", $this->output->errors));

        $public = $this->directory->path . '/project/public/themes';
        self::assertFileExists($public . '/acme-harbour/1.2.0/preview/index.html');
        self::assertFileExists($public . '/acme-harbour/1.2.0/preview/screenshot.png');
        self::assertCount(2, $this->output->lines);
        self::assertStringEndsWith(', with screenshots.', $this->output->lines[1]);
        self::assertDirectoryDoesNotExist($this->directory->path . '/workspace');
    }

    public function testWithoutScreenshotsOrTheToolsThePickerShowsTheDescription(): void
    {
        $this->command([], $this->node('exit 7'))->run(new ArrayCommandInput(), $this->output);
        $this->command([])->run(new ArrayCommandInput([], [], ['no-screenshot']), $this->output);

        self::assertStringContainsString('. No screenshots: The screenshots could not be taken. The screenshot script stopped with code 7. The theme picker shows the theme\'s description instead.', $this->output->lines[0]);
        self::assertStringEndsWith(', without screenshots.', $this->output->lines[1]);
    }

    public function testSaysWhichThemeCouldNotBePublished(): void
    {
        $broken = new class ($this->directory->path . '/broken') implements ThemeInterface {
            public function __construct(private string $root)
            {
            }

            public function manifest(): ThemeManifest
            {
                return new ThemeManifest('acme-broken', 'Broken', 'Broken.', '1.0.0', new ThemeRequirement('^0.2'));
            }

            public function paths(): ThemePaths
            {
                return new ThemePaths($this->root);
            }
        };

        self::assertSame(1, $this->command([$broken])->run(new ArrayCommandInput([], [], ['no-screenshot']), $this->output));
        self::assertStringStartsWith('The preview of "Broken" could not be published. ', $this->output->errors[0]);
        self::assertCount(1, $this->output->lines, 'the default theme is still published');
    }

    /**
     * @param list<ThemeInterface> $themes
     */
    private function command(array $themes, string $node = 'node'): PublishThemeAssetsCommand
    {
        return new PublishThemeAssetsCommand(
            new ThemeRegistry($themes),
            new PublishedPreviews(TenantConfig::fromArray([], $this->directory->path . '/project')),
            new PreviewTools($node),
            $this->directory->path . '/workspace',
        );
    }

    private function node(string $script): string
    {
        $file = $this->directory->write('node-' . md5($script), "#!/bin/sh\n" . $script . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
