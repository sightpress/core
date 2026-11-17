<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Page\Contract\PublishIssue;

/**
 * JSON Pointers into a page's content (`/blocks/2/data/alt`) and the form names and ids that go with them, so an
 * error or a publish check about `/blocks/2/data/alt` links to the field `en-gb-blocks-2-data-alt` named
 * `blocks[2][data][alt]`.
 */
final class Pointer
{
    /**
     * `blocks[2][data][alt]` for `/blocks/2/data/alt`.
     */
    public static function name(string $pointer): string
    {
        $parts = self::parts($pointer);
        $first = array_shift($parts) ?? '';

        return $first . implode('', array_map(static fn (string $part): string => '[' . $part . ']', $parts));
    }

    /**
     * The `id` of the field or fieldset, as {@see PublishIssue::anchorFor()} makes it.
     */
    public static function id(string $locale, string $pointer): string
    {
        return PublishIssue::anchorFor($locale, $pointer);
    }

    /**
     * @return list<string>
     */
    public static function parts(string $pointer): array
    {
        return array_values(array_map(
            static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part),
            array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== ''),
        ));
    }

    /**
     * The pointer one level up: `/blocks/2` for `/blocks/2/data`; `''` at the top.
     */
    public static function parent(string $pointer): string
    {
        $parts = self::parts($pointer);
        array_pop($parts);

        return $parts === [] ? '' : '/' . implode('/', $parts);
    }

    /**
     * The value at the pointer, or null when there is nothing there.
     *
     * @param array<array-key, mixed> $document
     */
    public static function get(array $document, string $pointer): mixed
    {
        $value = $document;
        foreach (self::parts($pointer) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * The document with the value at the pointer replaced (the parents must exist).
     *
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    public static function set(array $document, string $pointer, mixed $value): array
    {
        $parts = self::parts($pointer);
        if ($parts === []) {
            return is_array($value) ? $value : $document;
        }
        $key = array_shift($parts);
        $child = $document[$key] ?? [];
        $document[$key] = $parts === [] ? $value : self::set(is_array($child) ? $child : [], '/' . implode('/', $parts), $value);

        return $document;
    }
}
