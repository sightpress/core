<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use JsonException;

/**
 * Reads a theme folder's `theme.json` and checks its shape: name, label, version, colours for light and dark mode
 * (the same names in both), optional "more contrast" overrides of known colours, and the other token groups.
 * Token values must be plain CSS values, so a theme can't inject rules into the generated stylesheet.
 */
final readonly class ThemeLoader
{
    public const string MANIFEST = 'theme.json';

    /** Colours every theme must define: core uses them for generated images, the manifest and focus rings. */
    public const array REQUIRED_COLORS = ['background', 'text', 'link', 'primary', 'on-primary', 'border', 'focus'];

    private const string NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    private const string TOKEN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const string VALUE = '/^[A-Za-z0-9 .,%#()+*\/\'"-]+$/';

    /**
     * @throws ThemeException with a sentence that says what to fix
     */
    public function load(string $directory): Theme
    {
        $directory = rtrim($directory, '/');
        $file = $directory . '/' . self::MANIFEST;
        $json = is_file($file) ? @file_get_contents($file) : false;
        if ($directory === '' || $json === false) {
            throw new ThemeException(sprintf('The theme in "%s" has no %s file.', $directory, self::MANIFEST));
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException(sprintf('%s is not valid JSON: %s.', $file, $exception->getMessage()), 0, $exception);
        }
        if (!is_array($data)) {
            throw new ThemeException(sprintf('%s must hold a JSON object.', $file));
        }

        try {
            return $this->theme($directory, $data);
        } catch (ThemeException $exception) {
            throw new ThemeException(sprintf('%s: %s', $file, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param non-empty-string $directory
     * @param array<mixed>     $data
     */
    private function theme(string $directory, array $data): Theme
    {
        $name = self::text($data, 'name');
        if (preg_match(self::NAME, $name) !== 1) {
            throw new ThemeException(sprintf('"name" must be lower-case words joined by "-", not "%s".', $name));
        }

        $colors = self::object($data, 'colors');
        $light = self::colors(self::object($colors, 'light'), 'colors.light');
        $dark = self::colors(self::object($colors, 'dark'), 'colors.dark');

        foreach (self::REQUIRED_COLORS as $required) {
            if (!isset($light[$required])) {
                throw new ThemeException(sprintf('"colors.light" must define the colour "%s".', $required));
            }
        }
        $missing = array_diff_key($light, $dark);
        $extra = array_diff_key($dark, $light);
        if ($missing !== [] || $extra !== []) {
            throw new ThemeException(sprintf(
                '"colors.light" and "colors.dark" must define the same colours; different: "%s".',
                implode('", "', array_keys($missing + $extra)),
            ));
        }

        $more = array_key_exists('more', $colors) ? self::object($colors, 'more') : [];
        $lightMore = array_key_exists('light', $more) ? self::colors(self::object($more, 'light'), 'colors.more.light') : [];
        $darkMore = array_key_exists('dark', $more) ? self::colors(self::object($more, 'dark'), 'colors.more.dark') : [];
        $unknown = array_diff_key($lightMore + $darkMore, $light);
        if ($unknown !== []) {
            throw new ThemeException(sprintf(
                '"colors.more" can only change colours that "colors.light" defines; unknown: "%s".',
                implode('", "', array_keys($unknown)),
            ));
        }

        $values = [];
        foreach (array_keys(ThemeTokens::GROUPS) as $group) {
            $values[$group] = array_key_exists($group, $data) ? self::values(self::object($data, $group), $group) : [];
        }
        if (!isset($values['typography']['size-base'])) {
            throw new ThemeException('"typography" must define "size-base", the base font size.');
        }

        return new Theme(
            $name,
            self::text($data, 'label'),
            self::text($data, 'version'),
            $directory,
            new ThemeTokens($light, $dark, $lightMore, $darkMore, $values),
        );
    }

    /**
     * @param array<mixed> $data
     * @return non-empty-string
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new ThemeException(sprintf('"%s" must be a text that is not empty.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ThemeException(sprintf('"%s" must be a JSON object.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<non-empty-string, Color>
     */
    private static function colors(array $data, string $where): array
    {
        $colors = [];
        foreach ($data as $name => $value) {
            $name = self::name($name, $where, self::NAME, 'lower-case words joined by "-", starting with a letter');
            if (!is_string($value)) {
                throw new ThemeException(sprintf('Colour "%s.%s" must be a text such as "#1a1a1f".', $where, $name));
            }
            try {
                $colors[$name] = Color::fromHex($value);
            } catch (ThemeException $exception) {
                throw new ThemeException(sprintf('Colour "%s.%s": %s', $where, $name, $exception->getMessage()), 0, $exception);
            }
        }

        return $colors;
    }

    /**
     * @param array<mixed> $data
     * @return array<array-key, string>
     */
    private static function values(array $data, string $group): array
    {
        $values = [];
        foreach ($data as $name => $value) {
            $name = self::name($name, $group, self::TOKEN, 'lower-case words or numbers joined by "-"');
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value) || preg_match(self::VALUE, $value) !== 1 || str_contains($value, '/*')) {
                throw new ThemeException(sprintf(
                    'Token "%s.%s" must be a plain CSS value (letters, digits, spaces and . , %% # ( ) + * / \' " -).',
                    $group,
                    $name,
                ));
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @return non-empty-string
     */
    private static function name(int|string $name, string $where, string $pattern, string $rule): string
    {
        $name = (string) $name;
        if ($name === '' || preg_match($pattern, $name) !== 1) {
            throw new ThemeException(sprintf('Token name "%s" in "%s" must be %s.', $name, $where, $rule));
        }

        return $name;
    }
}
