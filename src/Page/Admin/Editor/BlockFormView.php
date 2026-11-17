<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Page\PageContent;
use SightPress\Module\Block\BlockType;

/**
 * What the page editor shows for a list of blocks: one fieldset per block, with a legend that says where it is
 * ("Block 2 of 5: Image"), a control for each field of its schema, the buttons that move or remove it, and its
 * errors. Lists inside a block (the pictures of a gallery, the columns and their blocks) get fieldsets of their
 * own. Every node has the JSON Pointer of what it shows, its form name and the `id` errors and publish checks
 * link to.
 *
 * Errors are given by pointer; one that isn't about a field shown here goes to the nearest block or list above it.
 */
final readonly class BlockFormView
{
    public function __construct(
        private BlockTypeCatalogue $types,
        private MediaChoices $media,
    ) {
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     * @param array<string, string>                                               $errors   pointer (`/blocks/2/data/alt`) => message
     * @param array<string, string>                                               $previews pointer of a block => its HTML
     */
    public function build(string $locale, array $blocks, array $errors = [], array $previews = []): EditorBlocks
    {
        $list = $this->blockList($locale, '/blocks', $blocks, 'Blocks', PageContent::MAX_BLOCKS, true, $previews);
        $known = [];
        self::collect($list, $known);
        $assigned = [];
        foreach ($errors as $pointer => $message) {
            $target = (string) $pointer;
            while ($target !== '' && !isset($known[$target])) {
                $target = Pointer::parent($target);
            }
            $assigned[$target === '' ? '/blocks' : $target][] = $message;
        }
        $summary = [];
        $list = self::assign($list, $assigned, $summary);

        return new EditorBlocks($list, $summary);
    }

    /**
     * The block types that can be added, by name: inside another block, only blocks without lists of their own.
     *
     * @return array<string, string> name => label
     */
    public function addable(bool $topLevel): array
    {
        $choices = [];
        foreach ($this->types->all() as $name => $type) {
            if ($topLevel || !self::holdsBlocks($type->schema())) {
                $choices[$name] = $type->label();
            }
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    /**
     * @param list<mixed>           $blocks
     * @param array<string, string> $previews
     *
     * @return array<string, mixed>
     */
    private function blockList(string $locale, string $pointer, array $blocks, string $label, int $max, bool $topLevel, array $previews): array
    {
        $views = [];
        $count = count($blocks);
        foreach (array_values($blocks) as $index => $block) {
            $views[] = $this->block($locale, $pointer . '/' . $index, is_array($block) ? $block : [], $index, $count, $previews);
        }

        return [
            'kind' => ControlKind::Blocks->value,
            'pointer' => $pointer,
            'id' => Pointer::id($locale, $pointer),
            'name' => Pointer::name($pointer),
            'label' => $label,
            'blocks' => $views,
            'can_add' => $count < $max,
            'add_choices' => $this->addable($topLevel),
            'top_level' => $topLevel,
            'errors' => [],
        ];
    }

    /**
     * @param array<array-key, mixed> $block
     * @param array<string, string>   $previews
     *
     * @return array<string, mixed>
     */
    private function block(string $locale, string $pointer, array $block, int $index, int $count, array $previews): array
    {
        $name = is_string($block['type'] ?? null) ? $block['type'] : '';
        $type = $this->types->get($name);
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $label = $type?->label() ?? sprintf('Unknown block "%s"', $name);

        return [
            'kind' => 'block',
            'pointer' => $pointer,
            'id' => Pointer::id($locale, $pointer),
            'name' => Pointer::name($pointer),
            'type' => $name,
            'version' => is_int($block['version'] ?? null) ? $block['version'] : ($type?->version() ?? 1),
            'label' => $label,
            'legend' => sprintf('Block %d of %d: %s', $index + 1, $count, $label),
            'short' => sprintf('block %d, %s', $index + 1, $label),
            'moves' => self::moves($index, $count, true),
            'controls' => $type === null ? [] : $this->controls($locale, $pointer . '/data', $type->schema(), $data, $previews),
            'kept' => $type === null ? json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'preview' => $previews[$pointer] ?? null,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed>    $schema
     * @param array<array-key, mixed> $data
     * @param array<string, string>   $previews
     *
     * @return list<array<string, mixed>>
     */
    private function controls(string $locale, string $pointer, array $schema, array $data, array $previews): array
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $controls = [];
        foreach (BlockData::properties($schema) as $name => $property) {
            $controls[] = $this->control($locale, $pointer . '/' . $name, $name, $property, $data[$name] ?? null, in_array($name, $required, true), $previews);
        }

        return $controls;
    }

    /**
     * @param array<string, mixed>  $schema
     * @param array<string, string> $previews
     *
     * @return array<string, mixed>
     */
    private function control(string $locale, string $pointer, string $name, array $schema, mixed $value, bool $required, array $previews): array
    {
        $kind = ControlKind::of($schema);
        $label = self::label($schema, $name);
        $hint = is_string($schema['description'] ?? null) ? $schema['description'] : null;
        if ($kind === ControlKind::Blocks) {
            $max = $schema['maxItems'] ?? PageContent::MAX_BLOCKS;

            return ['hint' => $hint] + $this->blockList($locale, $pointer, is_array($value) ? array_values($value) : [], $label, is_int($max) ? $max : PageContent::MAX_BLOCKS, false, $previews);
        }
        $control = [
            'kind' => $kind->value,
            'pointer' => $pointer,
            'id' => Pointer::id($locale, $pointer),
            'name' => Pointer::name($pointer),
            'label' => $label,
            'hint' => $hint,
            'required' => $required,
            'errors' => [],
        ];
        if ($kind === ControlKind::Items) {
            return $control + $this->items($locale, $pointer, $schema, is_array($value) ? array_values($value) : [], $label, $previews);
        }
        $maxLength = $schema['maxLength'] ?? null;

        return $control + [
            'value' => is_scalar($value) && !is_bool($value) ? (string) $value : '',
            'checked' => $value === true,
            'maxlength' => is_int($maxLength) ? $maxLength : null,
            'choices' => match ($kind) {
                ControlKind::Select => self::selectChoices($schema),
                ControlKind::Media => $this->media->for(is_string($schema['format'] ?? null) ? $schema['format'] : '', is_int($value) ? $value : null),
                default => [],
            },
        ];
    }

    /**
     * @param array<string, mixed>  $schema
     * @param list<mixed>           $items
     * @param array<string, string> $previews
     *
     * @return array<string, mixed>
     */
    private function items(string $locale, string $pointer, array $schema, array $items, string $label, array $previews): array
    {
        $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : [];
        /** @var array<string, mixed> $itemSchema */
        $noun = is_string($itemSchema['title'] ?? null) ? $itemSchema['title'] : 'Item';
        $min = $schema['minItems'] ?? 0;
        $max = $schema['maxItems'] ?? PHP_INT_MAX;
        $count = count($items);
        $views = [];
        foreach ($items as $index => $item) {
            $at = $pointer . '/' . $index;
            $moves = self::moves($index, $count, $count > (is_int($min) ? $min : 0));
            $views[] = [
                'kind' => 'item',
                'pointer' => $at,
                'id' => Pointer::id($locale, $at),
                'name' => Pointer::name($at),
                'legend' => sprintf('%s %d of %d', $noun, $index + 1, $count),
                'short' => sprintf('%s %d', mb_strtolower($noun), $index + 1),
                'moves' => $moves,
                'controls' => $this->controls($locale, $at, $itemSchema, is_array($item) ? $item : [], $previews),
                'errors' => [],
            ];
        }

        return [
            'items' => $views,
            'noun' => $noun,
            'can_add' => $count < (is_int($max) ? $max : PHP_INT_MAX),
            'list_label' => $label,
        ];
    }

    /**
     * @return list<string>
     */
    private static function moves(int $index, int $count, bool $removable): array
    {
        return array_keys(array_filter([
            'up' => $index > 0,
            'down' => $index < $count - 1,
            'remove' => $removable,
        ]));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, string>
     */
    private static function selectChoices(array $schema): array
    {
        $choices = [];
        foreach (ControlKind::choices($schema) as $choice) {
            $choices[(string) $choice] = is_int($choice) ? (string) $choice : self::humanise($choice);
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function label(array $schema, string $name): string
    {
        return is_string($schema['title'] ?? null) && $schema['title'] !== '' ? $schema['title'] : self::humanise($name);
    }

    private static function humanise(string $name): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $name));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function holdsBlocks(array $schema): bool
    {
        foreach (BlockData::properties($schema) as $property) {
            $kind = ControlKind::of($property);
            $items = is_array($property['items'] ?? null) ? $property['items'] : [];
            /** @var array<string, mixed> $items */
            if ($kind === ControlKind::Blocks || $kind === ControlKind::Items && self::holdsBlocks($items)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $known
     */
    private static function collect(array $node, array &$known): void
    {
        if (is_string($node['pointer'] ?? null)) {
            $known[$node['pointer']] = true;
        }
        foreach (['blocks', 'controls', 'items'] as $children) {
            foreach (is_array($node[$children] ?? null) ? $node[$children] : [] as $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    self::collect($child, $known);
                }
            }
        }
    }

    /**
     * Gives every node its errors and lists them, in the order of the page, for the error summary.
     *
     * @param array<string, mixed>              $node
     * @param array<string, list<string>>       $assigned
     * @param list<array{id: string, message: string}> $summary
     *
     * @return array<string, mixed>
     */
    private static function assign(array $node, array $assigned, array &$summary): array
    {
        $pointer = is_string($node['pointer'] ?? null) ? $node['pointer'] : '';
        $id = is_string($node['id'] ?? null) ? $node['id'] : '';
        $node['errors'] = $assigned[$pointer] ?? [];
        $about = $node['legend'] ?? $node['label'] ?? null;
        foreach ($node['errors'] as $message) {
            $summary[] = ['id' => $id, 'message' => is_string($about) ? $about . ': ' . $message : $message];
        }
        foreach (['blocks', 'controls', 'items'] as $children) {
            if (is_array($node[$children] ?? null)) {
                $node[$children] = array_map(
                    static function (mixed $child) use ($assigned, &$summary): mixed {
                        /** @var array<string, mixed> $child */
                        return is_array($child) ? self::assign($child, $assigned, $summary) : $child;
                    },
                    $node[$children],
                );
            }
        }

        return $node;
    }
}
