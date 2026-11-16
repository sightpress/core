<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\ColorMode;
use SightPress\Core\Theme\ContrastPair;
use SightPress\Core\Theme\ContrastUse;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeTokens;

#[CoversClass(ThemeLoader::class)]
#[CoversClass(Theme::class)]
#[CoversClass(ThemeException::class)]
#[UsesClass(Color::class)]
#[UsesClass(ColorMode::class)]
#[UsesClass(ContrastPair::class)]
#[UsesClass(ContrastUse::class)]
#[UsesClass(ThemeTokens::class)]
#[UsesClass(TemplatePaths::class)]
final class ThemeLoaderTest extends TestCase
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

    public function testTheDefaultThemeLoads(): void
    {
        $theme = new ThemeLoader()->load(TemplatePaths::defaultTheme() . '/');

        self::assertSame('default', $theme->name);
        self::assertSame('SightPress Default', $theme->label);
        self::assertSame(TemplatePaths::defaultTheme(), $theme->directory);
        self::assertSame('1.125rem', $theme->tokens->value('typography', 'size-base'));
        foreach (ThemeLoader::REQUIRED_COLORS as $name) {
            foreach (ColorMode::cases() as $mode) {
                self::assertNotNull($theme->tokens->color($name, $mode), $name . ' in ' . $mode->label());
            }
        }
    }

    public function testAMinimalThemeLoadsWithTrimmedTextsAndNumbersAsValues(): void
    {
        $data = self::minimal();
        $data['label'] = '  Plain  ';
        $data['spacing'] = ['1' => '0.25rem'];
        $data = self::change($data, ['typography', 'line-height'], 1.5);
        $data = self::change($data, ['colors', 'more'], ['light' => ['text' => '#000'], 'dark' => ['text' => '#fff']]);
        $theme = $this->load($data);

        self::assertSame('Plain', $theme->label);
        self::assertSame('1.5', $theme->tokens->value('typography', 'line-height'));
        self::assertSame('0.25rem', $theme->tokens->value('spacing', '1'));
        self::assertSame('#000000', $theme->tokens->color('text', ColorMode::LightMore)?->hex);
        self::assertSame('#ffffff', $theme->tokens->color('text', ColorMode::DarkMore)?->hex);
        self::assertSame([], $theme->tokens->values['radius']);
        self::assertSame([], $theme->contrast);
    }

    public function testContrastPairsAreReadInOrder(): void
    {
        $data = self::minimal();
        $data['contrast'] = ['text' => [['text', 'background']], 'ui' => [['border', 'background'], ['focus', 'background']]];
        $theme = $this->load($data);

        self::assertSame(
            ['text on background (text)', 'border on background (ui)', 'focus on background (ui)'],
            array_map(static fn (ContrastPair $p): string => sprintf('%s on %s (%s)', $p->foreground, $p->background, $p->use->value), $theme->contrast),
        );
    }

    public function testAFolderWithoutThemeJsonIsRefused(): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage(sprintf('The theme in "%s/none" has no theme.json file.', $this->directory->path));

        new ThemeLoader()->load($this->directory->path . '/none');
    }

    public function testInvalidJsonIsRefused(): void
    {
        $this->directory->write('theme.json', '{"name": ');

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('theme.json is not valid JSON: Syntax error.');

        new ThemeLoader()->load($this->directory->path);
    }

    public function testJsonThatIsNotAnObjectIsRefused(): void
    {
        $this->directory->write('theme.json', '"default"');

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('theme.json must hold a JSON object.');

        new ThemeLoader()->load($this->directory->path);
    }

    /**
     * Each case changes the minimal theme: keys are dotted paths, a null value removes the key.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'missing name' => [['name' => null], '"name" must be a text that is not empty.'];
        yield 'bad name' => [['name' => 'My Theme'], '"name" must be lower-case words joined by "-", not "My Theme".'];
        yield 'blank version' => [['version' => ' '], '"version" must be a text that is not empty.'];
        yield 'colors a list' => [['colors' => ['#fff']], '"colors" must be a JSON object.'];
        yield 'no dark' => [['colors.dark' => null], '"dark" must be a JSON object.'];
        yield 'missing required' => [['colors.light.focus' => null], '"colors.light" must define the colour "focus".'];
        yield 'dark differs' => [
            ['colors.dark.focus' => null, 'colors.dark.mark' => '#000'],
            '"colors.light" and "colors.dark" must define the same colours; different: "focus", "mark".',
        ];
        yield 'bad colour' => [['colors.light.text' => 'black'], 'Colour "colors.light.text": "black" is not a colour'];
        yield 'colour not text' => [['colors.light.text' => 0], 'Colour "colors.light.text" must be a text such as "#1a1a1f".'];
        yield 'bad colour name' => [
            ['colors.light.Text' => '#000'],
            'Token name "Text" in "colors.light" must be lower-case words joined by "-", starting with a letter.',
        ];
        yield 'colour name a number' => [['colors.light.1' => '#000'], 'Token name "1" in "colors.light" must be'];
        yield 'unknown more colour' => [
            ['colors.more' => ['dark' => ['glow' => '#fff']]],
            '"colors.more" can only change colours that "colors.light" defines; unknown: "glow".',
        ];
        yield 'no base size' => [['typography' => []], '"typography" must define "size-base", the base font size.'];
        yield 'css injection' => [
            ['typography.size-base' => '1rem; } body { display: none'],
            'Token "typography.size-base" must be a plain CSS value',
        ];
        yield 'comment' => [['radius' => ['small' => '1px /* x */']], 'Token "radius.small" must be a plain CSS value'];
        yield 'value not text' => [['radius' => ['small' => ['1px']]], 'Token "radius.small" must be a plain CSS value'];
        yield 'contrast a list' => [['contrast' => [['text', 'background']]], '"contrast" must be a JSON object.'];
        yield 'unknown contrast use' => [['contrast' => ['large' => []]], '"contrast" can only hold "text" and "ui" lists, not "large".'];
        yield 'contrast use not a list' => [['contrast' => ['text' => ['a' => 'b']]], '"contrast.text" must be a list of [foreground, background] pairs.'];
        yield 'pair of one' => [['contrast' => ['ui' => [['border']]]], 'Pair 1 of "contrast.ui" must be two colour names, for example ["text", "background"].'];
        yield 'pair not names' => [['contrast' => ['ui' => [['border', 1]]]], 'Pair 1 of "contrast.ui" must be two colour names'];
        yield 'pair not a list' => [['contrast' => ['text' => ['text']]], 'Pair 1 of "contrast.text" must be two colour names'];
        yield 'unknown pair colour' => [
            ['contrast' => ['text' => [['text', 'background'], ['text', 'paper']]]],
            'Pair 2 of "contrast.text" names the colour "paper", which "colors.light" doesn\'t define.',
        ];
        yield 'empty pair colour' => [['contrast' => ['text' => [['', 'background']]]], 'names the colour "", which'];
        yield 'bad value name' => [['spacing' => ['Big' => '1rem']], 'Token name "Big" in "spacing" must be lower-case words or numbers joined by "-".'];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('mistakes')]
    public function testMistakesAreRefusedWithTheFileAndWhatToFix(array $changes, string $message): void
    {
        $data = self::minimal();
        foreach ($changes as $path => $value) {
            $data = self::change($data, explode('.', $path), $value);
        }

        try {
            $this->load($data);
            self::fail('The theme should be refused.');
        } catch (ThemeException $exception) {
            self::assertStringStartsWith($this->directory->path . '/theme.json: ', $exception->getMessage());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     * @return array<mixed>
     */
    private static function change(array $data, array $keys, mixed $value): array
    {
        $key = (string) array_shift($keys);
        if ($keys === []) {
            if ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }
        $child = $data[$key] ?? [];
        $data[$key] = self::change(is_array($child) ? $child : [], $keys, $value);

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function load(array $data): Theme
    {
        $this->directory->write('theme.json', json_encode($data, JSON_THROW_ON_ERROR));

        return new ThemeLoader()->load($this->directory->path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimal(): array
    {
        $light = array_fill_keys(ThemeLoader::REQUIRED_COLORS, '#000000');
        $light['background'] = '#ffffff';

        return [
            'name' => 'plain',
            'label' => 'Plain',
            'version' => '1.0.0',
            'colors' => ['light' => $light, 'dark' => array_fill_keys(ThemeLoader::REQUIRED_COLORS, '#ffffff')],
            'typography' => ['size-base' => '1rem'],
        ];
    }
}
