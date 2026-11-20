<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Command\PreviewThemeCommand;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Module\Console\Command;

#[CoversClass(PreviewThemeCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class PreviewThemeCommandTest extends TestCase
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

    public function testDescribesItself(): void
    {
        $command = new PreviewThemeCommand();

        self::assertSame('theme:preview', $command->name());
        self::assertSame('Writes a theme\'s preview page as a static folder, and optionally its screenshots.', $command->description());
        self::assertSame(['theme', 'out', 'locale', 'screenshot'], array_map(static fn ($p) => $p->name, $command->parameters()));
        self::assertTrue($command->parameters()[0]->required);
    }

    public function testWritesThePreviewAndTakesTheScreenshots(): void
    {
        $out = $this->directory->path . '/out';
        $workspace = $this->directory->path . '/workspace';
        $command = new PreviewThemeCommand(new PreviewTools($this->node('touch "$2/screenshot.png"')), $workspace);

        $status = $command->run(new ArrayCommandInput(['theme' => 'default'], ['out' => $out, 'locale' => ' '], ['screenshot']), $this->output);

        self::assertSame(Command::SUCCESS, $status, implode("\n", $this->output->errors));
        self::assertFileExists($out . '/index.html');
        self::assertFileExists($out . '/screenshot.png');
        self::assertDirectoryDoesNotExist($workspace);
        self::assertSame([
            'The preview of "SightPress Default" ' . \SightPress\ThemeDefault\DefaultTheme::VERSION . ' is in ' . $out . ': open index.html in any browser; it works offline.',
            'Screenshots: screenshot.png, screenshot-dark.png, page.png.',
        ], $this->output->lines);
    }

    public function testWithoutScreenshotsOnlyWritesTheFolderAndAsksForAPreviewDescription(): void
    {
        $out = $this->directory->path . '/out';
        $theme = $this->directory->write('plain/theme.json', (string) file_get_contents(TemplatePaths::defaultTheme() . '/theme.json'));
        $command = new PreviewThemeCommand(new PreviewTools($this->node('exit 9')));

        self::assertSame(Command::SUCCESS, $command->run(new ArrayCommandInput(['theme' => dirname($theme)], ['out' => $out, 'locale' => 'en-GB']), $this->output));
        self::assertFileExists($out . '/index.html');
        self::assertSame('The theme has no preview description: add previewDescription to its manifest, the text people get instead of the screenshot.', $this->output->lines[1]);
        self::assertCount(2, $this->output->lines);
        self::assertSame([], $this->output->errors);
    }

    public function testFailsWhenTheScreenshotsFail(): void
    {
        $command = new PreviewThemeCommand(new PreviewTools($this->node('exit 5')));
        $cwd = (string) getcwd();
        chdir($this->directory->path);
        try {
            $status = $command->run(new ArrayCommandInput(['theme' => 'default'], [], ['screenshot']), $this->output);
        } finally {
            chdir($cwd);
        }

        self::assertSame(Command::FAILURE, $status);
        self::assertFileExists($this->directory->path . '/build/theme-preview/default/index.html');
        self::assertSame(['The screenshots could not be taken. The screenshot script stopped with code 5.'], $this->output->errors);
    }

    public function testSaysWhenThereIsNoThemeThere(): void
    {
        $status = new PreviewThemeCommand()->run(new ArrayCommandInput(['theme' => $this->directory->path . '/nothing']), $this->output);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringStartsWith('The preview could not be made. The theme in "' . $this->directory->path . '/nothing" has no theme.json file.', $this->output->errors[0]);
    }

    private function node(string $script): string
    {
        $file = $this->directory->write('node', "#!/bin/sh\n" . $script . "\n");
        chmod($file, 0o755);

        return $file;
    }
}
