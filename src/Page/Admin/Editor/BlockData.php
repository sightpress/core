<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Module\Block\BlockType;

/**
 * Turns the page editor's form back into blocks, following each block type's schema: text stays text, numbers
 * and media ids become whole numbers, a ticked box becomes `true`. A block's looks keep only the choices that aren't
 * the default. Empty optional fields are left out, so the
 * block module can say what is missing. Nothing here decides whether the blocks can be saved: the block module
 * checks and cleans them when the page is saved.
 */
final readonly class BlockData
{
    /**
     * The field that carries, as JSON, the data of a block whose type isn't installed, so saving the page keeps it.
     */
    public const string KEPT = 'kept';

    public function __construct(private BlockTypeCatalogue $types)
    {
    }

    /**
     * @param mixed $posted the `blocks` part of the form
     *
     * @return list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}>
     */
    public function read(mixed $posted): array
    {
        $blocks = [];
        foreach (self::list($posted) as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $type = $this->types->get($block['type']);
            $version = $block['version'] ?? null;
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $styles = BlockStyleChoices::clean($this->types->stylesOf($block['type']), $block['styles'] ?? null);
            $blocks[] = [
                'type' => $block['type'],
                'version' => is_numeric($version) ? max(1, (int) $version) : ($type?->version() ?? 1),
                'data' => $type === null ? self::kept($block[self::KEPT] ?? null) : $this->object($type->schema(), $data),
            ] + ($styles === [] ? [] : ['styles' => $styles]);
        }

        return $blocks;
    }

    /**
     * A new, empty block of the type: lists that need items (the two columns of a columns block, the first
     * picture of a gallery) get empty ones.
     *
     * @return array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}
     */
    public static function blank(BlockType $type): array
    {
        /** @var array<string, mixed> $data */
        $data = self::skeleton($type->schema()) ?? [];

        return ['type' => $type->name(), 'version' => $type->version(), 'data' => $data];
    }

    /**
     * A new, empty value for the schema: an object with its required lists filled, a list with its minimum
     * number of items, or null.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<array-key, mixed>|null
     */
    public static function skeleton(array $schema): ?array
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            $skeleton = [];
            foreach (self::properties($schema) as $name => $property) {
                $value = ($property['type'] ?? null) === 'array' ? self::skeleton($property) : null;
                if ($value !== null && $value !== []) {
                    $skeleton[$name] = $value;
                }
            }

            return $skeleton;
        }
        if ($type === 'array' && ControlKind::of($schema) === ControlKind::Items) {
            /** @var array<string, mixed> $items */
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $minimum = $schema['minItems'] ?? 0;

            return array_fill(0, is_int($minimum) ? max(0, $minimum) : 0, self::skeleton($items) ?? []);
        }

        return $type === 'array' ? [] : null;
    }

    /**
     * @param array<string, mixed>    $schema
     * @param array<array-key, mixed> $posted
     *
     * @return array<string, mixed>
     */
    private function object(array $schema, array $posted): array
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $data = [];
        foreach (self::properties($schema) as $name => $property) {
            $value = $this->value($property, $posted[$name] ?? null, in_array($name, $required, true));
            if ($value !== null) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function value(array $schema, mixed $posted, bool $required): mixed
    {
        $kind = ControlKind::of($schema);
        if ($kind === ControlKind::Checkbox) {
            return $posted === '1' || $posted === true ? true : ($required ? false : null);
        }
        if ($kind === ControlKind::Blocks) {
            return $this->read($posted);
        }
        if ($kind === ControlKind::Items) {
            /** @var array<string, mixed> $items */
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_map(fn (mixed $item): array => $this->object($items, is_array($item) ? $item : []), self::list($posted));
        }
        if (!is_string($posted) || trim($posted) === '') {
            return null;
        }
        if ($kind === ControlKind::Select) {
            foreach (ControlKind::choices($schema) as $choice) {
                if ((string) $choice === $posted) {
                    return $choice;
                }
            }

            return $posted;
        }
        if (($schema['type'] ?? null) === 'integer') {
            return preg_match('/^\s*-?\d+\s*$/', $posted) === 1 ? (int) $posted : $posted;
        }
        if ($kind === ControlKind::Number) {
            return is_numeric(trim($posted)) ? (float) $posted : $posted;
        }

        return str_replace("\r\n", "\n", $posted);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
     */
    public static function properties(array $schema): array
    {
        $properties = [];
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            if (is_string($name) && is_array($property)) {
                /** @var array<string, mixed> $property */
                $properties[$name] = $property;
            }
        }

        return $properties;
    }

    /**
     * The items of a posted list, in the order of their numeric keys.
     *
     * @return list<mixed>
     */
    private static function list(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }
        ksort($posted, SORT_NUMERIC);

        return array_values($posted);
    }

    /**
     * The data of a block whose type isn't installed (any more), as the form carried it.
     *
     * @return array<string, mixed>
     */
    private static function kept(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return [];
        }
        /** @var array<string, mixed> $data */
        return $data;
    }
}
