<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Schema;

use Closure;
use SightPress\Core\Media\Contract\MediaKind;

/**
 * Visits every value in block data together with the part of the schema that describes it, so core can find rich
 * text fields and media ids in any block type, and change them (sanitising) on the way.
 */
final class SchemaWalker
{
    /**
     * @param array<mixed>                                                    $schema
     * @param Closure(array<mixed> $schema, mixed $value, string $path): mixed $visitor gets the JSON Pointer of the
     *                                                                                  value in the data; returns the
     *                                                                                  value to keep
     */
    public static function walk(array $schema, mixed $value, Closure $visitor, string $path = ''): mixed
    {
        if (is_array($value)) {
            $properties = $schema['properties'] ?? null;
            if (is_array($properties) && !array_is_list($value)) {
                foreach ($value as $name => $item) {
                    if (is_array($properties[$name] ?? null)) {
                        $value[$name] = self::walk($properties[$name], $item, $visitor, $path . '/' . self::escape((string) $name));
                    }
                }
            }
            $items = $schema['items'] ?? null;
            if (is_array($items) && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    $value[$index] = self::walk($items, $item, $visitor, $path . '/' . $index);
                }
            }
        }

        return $visitor($schema, $value, $path);
    }

    /**
     * Ids of media library files in the data (`format: media` or `media-<kind>`).
     *
     * @param array<mixed> $schema
     *
     * @return list<int>
     */
    public static function mediaIds(array $schema, mixed $value): array
    {
        return array_map(static fn (array $reference): int => $reference['id'], self::mediaReferences($schema, $value));
    }

    /**
     * Media library files in the data, with where they are and the kind of file the field needs (null: any).
     *
     * @param array<mixed> $schema
     *
     * @return list<array{id: int, path: string, kind: MediaKind|null}>
     */
    public static function mediaReferences(array $schema, mixed $value): array
    {
        $references = [];
        self::walk($schema, $value, static function (array $schema, mixed $value, string $path) use (&$references): mixed {
            $format = $schema['format'] ?? null;
            if (is_string($format) && SchemaConstraints::isMediaFormat($format) && is_int($value)) {
                $references[] = ['id' => $value, 'path' => $path, 'kind' => SchemaConstraints::mediaKind($format)];
            }

            return $value;
        });

        return $references;
    }

    private static function escape(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }
}
