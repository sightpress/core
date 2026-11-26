<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Template\Message;
use SightPress\Core\Content\BlockTypeCatalogue;

/**
 * The page editor's buttons that change the shape of the content without saving it: add a block (or a picture,
 * a column), move one up or down, remove one. The form is sent with the button (`op`: `add:/blocks`,
 * `up:/blocks/2`, `remove:/blocks/0/data/columns/1/blocks/0` …); this applies it to the blocks as they were in the
 * form and says, in words, what changed and where focus goes next.
 */
final readonly class BlockOperation
{
    public const array MOVES = ['up', 'down', 'remove'];

    public function __construct(private BlockTypeCatalogue $types)
    {
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     * @param string|null                                                         $addType the block type chosen for `add` on a list of blocks
     */
    public function apply(string $locale, array $blocks, string $op, ?string $addType = null): ?OperationResult
    {
        [$action, $pointer] = array_pad(explode(':', $op, 2), 2, '');
        $document = ['blocks' => $blocks];
        if ($action === 'add') {
            return $this->add($locale, $document, $pointer, $addType);
        }
        if (!in_array($action, self::MOVES, true)) {
            return null;
        }
        $listPointer = Pointer::parent($pointer);
        $list = $this->listAt($document, $listPointer);
        $parts = Pointer::parts($pointer);
        $index = end($parts);
        $items = Pointer::get($document, $listPointer);
        if ($list === null || !is_array($items) || !ctype_digit((string) $index) || !array_key_exists((int) $index, $items)) {
            return null;
        }
        $index = (int) $index;
        $count = count($items);
        $what = $this->describe($list, $items[$index], $index);
        if ($action === 'remove') {
            array_splice($items, $index, 1);

            return $this->result($document, $listPointer, array_values($items), new Message('Removed {what}.', ['what' => $what]), Pointer::id($locale, $listPointer));
        }
        $to = $action === 'up' ? $index - 1 : $index + 1;
        if ($to < 0 || $to >= $count) {
            return null;
        }
        [$items[$index], $items[$to]] = [$items[$to], $items[$index]];
        $items = array_values($items);
        $moved = Pointer::id($locale, $listPointer . '/' . $to);
        $still = $action === 'up' ? $to > 0 : $to < $count - 1;
        $focus = $moved . '-' . ($still ? $action : ($action === 'up' ? 'down' : 'up'));

        return $this->result($document, $listPointer, $items, new Message('Moved {what} {action}. It is now {noun} {to} of {value}.', ['what' => $what, 'action' => $action, 'noun' => Message::of($list['noun']), 'to' => $to + 1, 'value' => (string) $count]), $focus);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function add(string $locale, array $document, string $pointer, ?string $addType): ?OperationResult
    {
        $list = $this->listAt($document, $pointer);
        $items = Pointer::get($document, $pointer);
        $items = is_array($items) ? array_values($items) : [];
        if ($list === null) {
            return null;
        }
        if ($list['blocks']) {
            $type = $addType === null ? null : $this->types->get($addType);
            if ($type === null) {
                return null;
            }
            $items[] = BlockData::blank($type);
            $message = new Message('Added a {label} block. It is {noun} {items} of {items2}.', ['label' => Message::of($type->label()), 'noun' => Message::of($list['noun']), 'items' => (string) count($items), 'items2' => (string) count($items)]);
        } else {
            $items[] = BlockData::skeleton($list['schema']) ?? [];
            $message = new Message('Added {noun} {items} of {items2}.', ['noun' => Message::of($list['noun']), 'items' => (string) count($items), 'items2' => (string) count($items)]);
        }

        return $this->result($document, $pointer, $items, $message, Pointer::id($locale, $pointer . '/' . (count($items) - 1)));
    }

    /**
     * @param array<string, mixed> $document
     * @param list<mixed>          $items
     */
    private function result(array $document, string $listPointer, array $items, string|Message $message, string $focus): OperationResult
    {
        $blocks = Pointer::set($document, $listPointer, $items)['blocks'] ?? [];
        /** @var list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks */
        return new OperationResult(array_values($blocks), new Message('{change} Save the page to keep this change.', ['change' => Message::of($message)]), $focus);
    }

    /**
     * @param array{blocks: bool, schema: array<string, mixed>, noun: string} $list
     */
    private function describe(array $list, mixed $item, int $index): Message
    {
        if (!$list['blocks']) {
            return new Message('{noun} {index}', ['noun' => Message::of($list['noun']), 'index' => $index + 1]);
        }
        $type = is_array($item) && is_string($item['type'] ?? null) ? $this->types->get($item['type']) : null;

        return new Message('block {index} ({unknown})', ['index' => $index + 1, 'unknown' => $type?->label() ?? 'unknown']);
    }

    /**
     * What the list at the pointer holds: blocks, or items of a block's own schema (pictures, columns).
     *
     * @param array<string, mixed> $document
     *
     * @return array{blocks: bool, schema: array<string, mixed>, noun: string}|null
     */
    private function listAt(array $document, string $pointer): ?array
    {
        $parts = Pointer::parts($pointer);
        if (array_shift($parts) !== 'blocks') {
            return null;
        }
        $list = ['blocks' => true, 'schema' => [], 'noun' => 'block'];
        $at = '/blocks';
        while ($parts !== []) {
            $index = array_shift($parts);
            $at .= '/' . $index;
            $schema = $list['schema'];
            if ($list['blocks']) {
                $block = Pointer::get($document, $at);
                $type = is_array($block) && is_string($block['type'] ?? null) ? $this->types->get($block['type']) : null;
                if ($type === null || array_shift($parts) !== 'data') {
                    return null;
                }
                $at .= '/data';
                $schema = $type->schema();
            }
            $name = array_shift($parts);
            $property = $name === null ? null : (BlockData::properties($schema)[$name] ?? null);
            if ($property === null) {
                return null;
            }
            $at .= '/' . $name;
            $kind = ControlKind::of($property);
            if ($kind === ControlKind::Blocks) {
                $list = ['blocks' => true, 'schema' => [], 'noun' => 'block'];
            } elseif ($kind === ControlKind::Items) {
                $items = is_array($property['items'] ?? null) ? $property['items'] : [];
                /** @var array<string, mixed> $items */
                $list = ['blocks' => false, 'schema' => $items, 'noun' => mb_strtolower(is_string($items['title'] ?? null) ? $items['title'] : 'item')];
            } else {
                return null;
            }
        }

        return $list;
    }
}
