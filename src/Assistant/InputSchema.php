<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

/**
 * Checks a task's input against its JSON Schema ({@see \SightPress\Module\Assistant\AssistantTask::inputSchema()})
 * before the prompt is built. It understands the part of JSON Schema that task inputs need: `type` (object, string,
 * integer, number, boolean, array), `properties`, `required`, `additionalProperties: false`, `items`, `enum`,
 * `minLength` / `maxLength`, `minimum` / `maximum` and `maxItems`. A keyword it doesn't know is a mistake in the task
 * and refuses every input, so nothing goes unchecked by accident.
 */
final class InputSchema
{
    private const array KEYWORDS = [
        '$schema', 'title', 'description', 'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
        'minLength', 'maxLength', 'minimum', 'maximum', 'maxItems', 'default',
    ];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> what is wrong, empty when the input is valid
     */
    public static function problems(array $schema, mixed $value, string $path = 'input'): array
    {
        $unknown = array_diff(array_keys($schema), self::KEYWORDS);
        if ($unknown !== []) {
            return [sprintf('The schema of %s uses %s, which isn\'t supported.', $path, implode(', ', $unknown))];
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return [sprintf('%s must be one of the allowed values.', $path)];
        }

        return match ($schema['type'] ?? null) {
            'object' => self::object($schema, $value, $path),
            'array' => self::list($schema, $value, $path),
            'string' => self::string($schema, $value, $path),
            'integer' => is_int($value) ? self::range($schema, $value, $path) : [sprintf('%s must be a whole number.', $path)],
            'number' => is_int($value) || is_float($value) ? self::range($schema, $value, $path) : [sprintf('%s must be a number.', $path)],
            'boolean' => is_bool($value) ? [] : [sprintf('%s must be true or false.', $path)],
            default => [sprintf('The schema of %s has no type, or one that isn\'t supported.', $path)],
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function object(array $schema, mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [sprintf('%s must be an object.', $path)];
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $problems = [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (is_string($name) && !array_key_exists($name, $value)) {
                $problems[] = sprintf('%s.%s is missing.', $path, $name);
            }
        }
        foreach ($value as $name => $item) {
            $property = $properties[$name] ?? null;
            if (is_array($property)) {
                /** @var array<string, mixed> $property */
                array_push($problems, ...self::problems($property, $item, $path . '.' . $name));
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $problems[] = sprintf('%s.%s isn\'t expected.', $path, $name);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function list(array $schema, mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [sprintf('%s must be a list.', $path)];
        }
        $max = $schema['maxItems'] ?? null;
        if (is_int($max) && count($value) > $max) {
            return [sprintf('%s may have at most %d items.', $path, $max)];
        }
        /** @var array<string, mixed>|null $items */
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        $problems = [];
        if ($items !== null) {
            foreach ($value as $index => $item) {
                array_push($problems, ...self::problems($items, $item, sprintf('%s[%d]', $path, $index)));
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function string(array $schema, mixed $value, string $path): array
    {
        if (!is_string($value)) {
            return [sprintf('%s must be text.', $path)];
        }
        $length = mb_strlen($value);
        $min = $schema['minLength'] ?? null;
        $max = $schema['maxLength'] ?? null;

        return match (true) {
            is_int($min) && $length < $min => [sprintf('%s must be at least %d characters long.', $path, $min)],
            is_int($max) && $length > $max => [sprintf('%s must be %d characters or fewer.', $path, $max)],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function range(array $schema, int|float $value, string $path): array
    {
        $min = $schema['minimum'] ?? null;
        $max = $schema['maximum'] ?? null;

        return match (true) {
            (is_int($min) || is_float($min)) && $value < $min => [sprintf('%s must be %s or more.', $path, $min)],
            (is_int($max) || is_float($max)) && $value > $max => [sprintf('%s must be %s or less.', $path, $max)],
            default => [],
        };
    }
}
