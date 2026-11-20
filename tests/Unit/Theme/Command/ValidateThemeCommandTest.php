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
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\Command\ValidateThemeCommand;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Core\Theme\ThemeChecker;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Module\Console\Command;

#[CoversClass(ValidateThemeCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class ValidateThemeCommandTest extends TestCase
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
        $command = $this->command();

        self::assertSame('theme:validate', $command->name());
        self::assertSame('Checks a theme as it is checked before it is applied, and says what to fix.', $command->description());
        self::assertSame(['theme', 'axe'], array_map(static fn ($p) => $p->name, $command->parameters()));
    }

    public function testWithoutAnArgumentEveryInstalledThemeIsChecked(): void
    {
        self::assertSame(Command::SUCCESS, $this->command()->run(new ArrayCommandInput(), $this->output));
        self::assertCount(2, $this->output->lines);
        self::assertStringStartsWith('The theme "SightPress Default" passes: ', $this->output->lines[0]);
        self::assertStringStartsWith('The theme "Harbour" passes: ', $this->output->lines[1]);
    }

    public function testChecksAThemeByItsIdOrItsFolder(): void
    {
        self::assertSame(Command::SUCCESS, $this->command()->run(new ArrayCommandInput(['theme' => 'acme-harbour']), $this->output));
        self::assertSame(Command::SUCCESS, $this->command()->run(new ArrayCommandInput(['theme' => TemplatePaths::defaultTheme()]), $this->output));
        self::assertCount(2, $this->output->lines);
    }

    public function testSaysWhatToFixAndWarns(): void
    {
        $this->directory->write('pale/theme.json', str_replace('"text-muted": "#4b4b57"', '"text-muted": "#c9c9cf"', (string) file_get_contents(TemplatePaths::defaultTheme() . '/theme.json')));

        self::assertSame(Command::FAILURE, $this->command()->run(new ArrayCommandInput(['theme' => $this->directory->path . '/pale']), $this->output));
        self::assertMatchesRegularExpression('/^The theme "SightPress Default" fails with \d+ problems:$/', $this->output->errors[0]);
        self::assertContains('2. The required template templates/master.html.twig is missing.', array_map(static fn (string $line): string => preg_replace('/^\d+\./', '2.', $line) ?? '', $this->output->errors));
    }

    public function testShowsTheWarnings(): void
    {
        $theme = new class () implements \SightPress\Theme\ThemeInterface {
            public function manifest(): \SightPress\Theme\ThemeManifest
            {
                return new \SightPress\Theme\ThemeManifest('acme-harbour', 'Harbour', 'Calm blues.', '1.2.0', new \SightPress\Theme\ThemeRequirement('^0.2'));
            }

            public function paths(): \SightPress\Theme\ThemePaths
            {
                return new HarbourTheme()->paths();
            }
        };

        self::assertSame(Command::SUCCESS, new ValidateThemeCommand(new ThemeRegistry([$theme]))->run(new ArrayCommandInput(['theme' => 'acme-harbour']), $this->output));
        self::assertStringStartsWith('Warning for "Harbour": The manifest has no previewDescription', $this->output->lines[0]);
    }

    public function testAThemeForAnotherContractFails(): void
    {
        $command = new ValidateThemeCommand(new ThemeRegistry([new HarbourTheme()], [], '0.3.0.0'));

        self::assertSame(Command::FAILURE, $command->run(new ArrayCommandInput(['theme' => 'acme-harbour']), $this->output));
        self::assertSame(['The theme "Harbour" fails with 1 problem:', '1. It needs sightpress/theme-interface ^0.2, and this SightPress has 0.3.0.0.'], $this->output->errors);
    }

    public function testScansThePreviewPageWithAxe(): void
    {
        $passing = $this->command($this->node('test -f "$2/index.html" && echo "light at 1280 px: no violations" && echo "dark at 1280 px: no violations"'));
        self::assertSame(Command::SUCCESS, $passing->run(new ArrayCommandInput(['theme' => 'default'], [], ['axe']), $this->output));
        self::assertSame('The preview page of "SightPress Default" passes axe: light at 1280 px: no violations; dark at 1280 px: no violations', $this->output->lines[1]);
        self::assertDirectoryDoesNotExist($this->directory->path . '/workspace');

        $failing = $this->command($this->node('echo "dark at 320 px: 1 violation"; exit 1'));
        self::assertSame(Command::FAILURE, $failing->run(new ArrayCommandInput(['theme' => 'default'], [], ['axe']), $this->output));
        self::assertSame(['The preview page of "SightPress Default" fails axe: dark at 320 px: 1 violation'], $this->output->errors);
    }

    public function testSaysWhenThePreviewForTheScanCantBeMade(): void
    {
        $source = new HarbourTheme()->paths()->root;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $this->directory->write('plain/' . substr($file->getPathname(), strlen($source) + 1), (string) file_get_contents($file->getPathname()));
        }
        // The checks don't need composer.json; the preview reads it for the theme's name and link.
        $this->directory->write('plain/composer.json', '{');

        self::assertSame(Command::FAILURE, $this->command($this->node('exit 0'))->run(new ArrayCommandInput(['theme' => $this->directory->path . '/plain'], [], ['axe']), $this->output));
        self::assertStringStartsWith('The preview page of "Harbour" fails axe: The preview page could not be made. ', $this->output->errors[0]);
    }

    private function command(?string $node = null): ValidateThemeCommand
    {
        return new ValidateThemeCommand(
            new ThemeRegistry([new HarbourTheme()]),
            new ThemeChecker(),
            new PreviewTools($node ?? 'node'),
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
