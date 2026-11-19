<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Command\ValidateThemeCommand;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeValidator;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;

#[CoversClass(ValidateThemeCommand::class)]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Template')]
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

    public function testDescribesItselfAndTakesAnOptionalFolder(): void
    {
        $command = self::command([]);

        self::assertSame('theme:validate', $command->name());
        self::assertSame('Checks a theme\'s colour contrast and base font size, and says what to fix.', $command->description());
        self::assertSame('path', $command->parameters()[0]->name);
        self::assertFalse($command->parameters()[0]->required);
    }

    public function testWithoutAFolderTheActiveThemeIsChecked(): void
    {
        $theme = $this->theme('#000000');

        self::assertSame(Command::SUCCESS, self::command([$theme])->run(self::input(null), $this->output));
        self::assertSame([
            'The theme "Plain" passes: 24 colour pairs checked in light and dark mode, with and without more contrast, '
            . 'and the base font size is at least 16 px.',
        ], $this->output->lines);
        self::assertSame([], $this->output->errors);
    }

    public function testWithoutAnyThemeFolderTheDefaultThemeIsChecked(): void
    {
        self::assertSame(Command::SUCCESS, self::command([])->run(self::input('  '), $this->output));
        self::assertStringStartsWith('The theme "SightPress Default" passes: ', $this->output->lines[0]);
    }

    public function testAFailingThemeListsEveryProblemOnStandardError(): void
    {
        $theme = $this->theme('#aaaaaa');

        self::assertSame(Command::FAILURE, self::command([])->run(self::input($theme), $this->output));
        self::assertSame([], $this->output->lines);
        self::assertSame('The theme "Plain" fails with 4 problems:', $this->output->errors[0]);
        self::assertStringStartsWith('1. In light mode, text "text" (#aaaaaa) on "background" (#ffffff) has a contrast of 2.32:1', $this->output->errors[1]);
        self::assertCount(5, $this->output->errors);
    }

    public function testOneProblemIsCountedInTheSingular(): void
    {
        $theme = $this->theme('#000000', size: '15px');

        self::command([])->run(self::input($theme), $this->output);

        self::assertSame('The theme "Plain" fails with 1 problem:', $this->output->errors[0]);
    }

    public function testAThemeThatCantBeLoadedSaysWhy(): void
    {
        self::assertSame(Command::FAILURE, self::command([])->run(self::input($this->directory->path . '/none'), $this->output));
        self::assertSame(
            [sprintf('The theme can\'t be loaded. The theme in "%s/none" has no theme.json file.', $this->directory->path)],
            $this->output->errors,
        );
    }

    /**
     * @param array<string, list<list<string>>>|null $pairs
     */
    private function theme(string $text, string $size = '1rem', ?array $pairs = null): string
    {
        $light = array_fill_keys(ThemeLoader::REQUIRED_COLORS, '#000000');
        $light['background'] = '#ffffff';
        $light['text'] = $text;
        $light += array_fill_keys([...ThemeValidator::CHART_MARKS, ThemeValidator::CHART_GRID, ThemeValidator::CHART_TEXT], '#000000');
        $folder = 'theme-' . bin2hex(random_bytes(4));
        $this->directory->write($folder . '/theme.json', json_encode([
            'name' => 'plain',
            'label' => 'Plain',
            'version' => '1.0.0',
            'colors' => ['light' => $light, 'dark' => $light],
            'typography' => ['size-base' => $size],
            'contrast' => $pairs ?? ['text' => [['text', 'background']]],
        ], JSON_THROW_ON_ERROR));

        return $this->directory->path . '/' . $folder;
    }

    /**
     * @param list<string> $themes
     */
    private static function command(array $themes): ValidateThemeCommand
    {
        return new ValidateThemeCommand(new ThemeLoader(), new ThemeValidator(), new TemplatePaths($themes, TemplatePaths::core()));
    }

    private static function input(?string $path): CommandInput
    {
        return new class ($path) implements CommandInput {
            public function __construct(private ?string $path)
            {
            }

            public function argument(string $name): ?string
            {
                return $name === 'path' ? $this->path : null;
            }

            public function option(string $name): ?string
            {
                return null;
            }

            public function flag(string $name): bool
            {
                return false;
            }
        };
    }
}
