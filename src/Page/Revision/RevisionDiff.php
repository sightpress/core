<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Template\Message;

/**
 * Compares a page in one language as two revisions kept it ({@see \SightPress\Core\Page\Entity\PageTranslation::snapshot()}):
 * first the details of the page (title, address, summary, search fields, whether the translation is a draft), then
 * each block. Blocks that are the same in both stay paired up; between them, blocks of the same type in the same
 * place count as changed and the rest as removed or added. Each changed field is compared word by word
 * ({@see TextDiff}); rich text is compared as the words a reader sees.
 */
final readonly class RevisionDiff
{
    public function __construct(private BlockTypeCatalogue $types)
    {
    }

    /**
     * @param array<array-key, mixed>|null $old the older version, or null when the translation is new
     * @param array<array-key, mixed>|null $new the newer version, or null when it no longer exists
     *
     * @return list<DiffSection> the details of the page first, then one section per block
     */
    public function compare(?array $old, ?array $new): array
    {
        $sections = [$this->section(new Message('About the page'), self::details($old), self::details($new), $old === null, $new === null)];
        $oldBlocks = self::blocks($old);
        $newBlocks = self::blocks($new);
        $count = count($newBlocks);
        $index = 0;
        foreach (self::align($oldBlocks, $newBlocks) as [$before, $after]) {
            if ($after === null) {
                /** @var array{type: string, data: array<array-key, mixed>} $before */
                $sections[] = $this->section(new Message('Removed block: {label}', ['label' => $this->label($before['type'])]), $this->fields($before), [], false, true);
                continue;
            }
            ++$index;
            $heading = new Message('Block {index} of {count}: {label}', ['index' => $index, 'count' => $count, 'label' => $this->label($after['type'])]);
            $sections[] = $this->section($heading, $before === null ? [] : $this->fields($before), $this->fields($after), $before === null, false);
        }

        return $sections;
    }

    /**
     * Whether nothing a reader would notice changed.
     *
     * @param list<DiffSection> $sections
     */
    public static function isUnchanged(array $sections): bool
    {
        foreach ($sections as $section) {
            if ($section->status !== DiffSection::SAME) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array{list<Message>, string|Message}> $old
     * @param array<string, array{list<Message>, string|Message}> $new
     */
    private function section(Message $heading, array $old, array $new, bool $added, bool $removed): DiffSection
    {
        $rows = [];
        foreach (array_keys($new + $old) as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            if ($after === null) {
                /** @var array{list<Message>, string|Message} $before */
                $rows[] = new DiffRow($before[0], TextDiff::REMOVED, [[TextDiff::REMOVED, $before[1]]]);
            } elseif ($before === null) {
                $rows[] = new DiffRow($after[0], TextDiff::ADDED, [[TextDiff::ADDED, $after[1]]]);
            } elseif ($before[1] instanceof Message || $after[1] instanceof Message) {
                if ((string) $before[1] !== (string) $after[1]) {
                    $rows[] = new DiffRow($after[0], DiffRow::CHANGED, [[TextDiff::REMOVED, $before[1]], [TextDiff::ADDED, $after[1]]]);
                }
            } elseif (!TextDiff::same($before[1], $after[1])) {
                $rows[] = new DiffRow($after[0], DiffRow::CHANGED, TextDiff::compare($before[1], $after[1]));
            }
        }
        $status = match (true) {
            $added => TextDiff::ADDED,
            $removed => TextDiff::REMOVED,
            $rows === [] => DiffSection::SAME,
            default => DiffRow::CHANGED,
        };

        return new DiffSection($heading, $status, $rows);
    }

    /**
     * The details of the page in one language, as label => text, leaving out the empty ones.
     *
     * @param array<array-key, mixed>|null $snapshot
     *
     * @return array<string, array{list<Message>, string|Message}>
     */
    private static function details(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }
        $path = $snapshot['path'] ?? null;
        $image = $snapshot['social_image'] ?? null;
        $fields = [
            'title' => [new Message('Title'), self::text($snapshot['title'] ?? null)],
            'path' => [new Message('Address'), is_string($path) ? '/' . $path : null],
            'summary' => [new Message('Summary'), self::text($snapshot['summary'] ?? null)],
            'seo_title' => [new Message('Title in search results'), self::text($snapshot['seo_title'] ?? null)],
            'description' => [new Message('Description for search engines'), self::text($snapshot['description'] ?? null)],
            'social_image' => [new Message('Picture shown when the page is shared'), is_int($image) ? new Message('File number {id} of the media library', ['id' => $image]) : null],
            'noindex' => [new Message('Ask search engines and AI search engines not to list this page'), ($snapshot['noindex'] ?? false) === true ? new Message('Yes') : null],
            'canonical_url' => [new Message('Address of the original'), self::text($snapshot['canonical_url'] ?? null)],
            'exclude_from_site_search' => [new Message('Leave this page out of the site’s own search'), ($snapshot['exclude_from_site_search'] ?? false) === true ? new Message('Yes') : null],
            'draft' => [new Message('This translation is a draft of its own'), ($snapshot['draft'] ?? false) === true ? new Message('Yes') : null],
        ];
        $details = [];
        foreach ($fields as $key => [$label, $value]) {
            if ($value !== null && $value !== '') {
                $details[$key] = [[$label], $value];
            }
        }

        return $details;
    }

    /**
     * @param array<array-key, mixed>|null $snapshot
     *
     * @return list<array{type: string, data: array<array-key, mixed>}>
     */
    private static function blocks(?array $snapshot): array
    {
        $blocks = [];
        foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
            if (is_array($block) && is_string($block['type'] ?? null)) {
                $blocks[] = ['type' => $block['type'], 'data' => is_array($block['data'] ?? null) ? $block['data'] : []];
            }
        }

        return $blocks;
    }

    /**
     * Pairs up the blocks of two versions: `[old, new]` for blocks in both (the same or changed), `[old, null]` for
     * removed ones and `[null, new]` for added ones, in the order of the newer version (removed ones where they
     * were). Blocks that are exactly the same are paired first; between them, blocks of the same type.
     *
     * @param list<array{type: string, data: array<array-key, mixed>}> $old
     * @param list<array{type: string, data: array<array-key, mixed>}> $new
     *
     * @return list<array{?array{type: string, data: array<array-key, mixed>}, ?array{type: string, data: array<array-key, mixed>}}>
     */
    private static function align(array $old, array $new): array
    {
        $key = static fn (array $block): string => (string) json_encode($block);
        $type = static fn (array $block): string => is_string($block['type'] ?? null) ? $block['type'] : '';
        $pairs = [];
        /** @var list<array{type: string, data: array<array-key, mixed>}> $gapOld */
        $gapOld = [];
        /** @var list<array{type: string, data: array<array-key, mixed>}> $gapNew */
        $gapNew = [];
        foreach ([...self::common(array_map($key, $old), array_map($key, $new)), [-1, -1]] as [$i, $j]) {
            if ($i !== null && $j !== null) {
                foreach (self::common(array_map($type, $gapOld), array_map($type, $gapNew)) as [$k, $l]) {
                    $pairs[] = [$k === null ? null : $gapOld[$k], $l === null ? null : $gapNew[$l]];
                }
                $gapOld = [];
                $gapNew = [];
                if ($i >= 0) {
                    $pairs[] = [$old[$i], $new[(int) $j]];
                }
            } elseif ($i !== null) {
                $gapOld[] = $old[$i];
            } else {
                $gapNew[] = $new[(int) $j];
            }
        }

        return $pairs;
    }

    /**
     * The longest run of values both lists have, in order, as index pairs: `[i, j]` for a value in both, `[i, null]`
     * for one only in the first list and `[null, j]` for one only in the second, in reading order.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{?int, ?int}>
     */
    private static function common(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $pairs = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $pairs[] = [$i++, $j++];
            } elseif ($j >= $m || ($i < $n && $lengths[$i + 1][$j] >= $lengths[$i][$j + 1])) {
                $pairs[] = [$i++, null];
            } else {
                $pairs[] = [null, $j++];
            }
        }

        return $pairs;
    }

    private function label(string $type): Message
    {
        return Message::of($this->types->get($type)?->label() ?? $type);
    }

    /**
     * The fields of a block as label => text, nested blocks and items included.
     *
     * @param array{type: string, data: array<array-key, mixed>} $block
     *
     * @return array<string, array{list<Message>, string|Message}>
     */
    private function fields(array $block): array
    {
        $schema = $this->types->get($block['type'])?->schema() ?? [];

        return $this->flatten($block['data'], $schema, [], '');
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $schema
     * @param list<Message>           $prefix
     *
     * @return array<string, array{list<Message>, string|Message}>
     */
    private function flatten(array $data, array $schema, array $prefix, string $key): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $fields = [];
        foreach (array_unique([...array_keys(array_intersect_key($properties, $data)), ...array_keys($data)]) as $name) {
            $value = $data[$name];
            $property = is_array($properties[$name] ?? null) ? $properties[$name] : [];
            $label = [...$prefix, Message::of(is_string($property['title'] ?? null) && $property['title'] !== '' ? $property['title'] : self::humanise((string) $name))];
            $path = $key . '/' . $name;
            if (is_array($value)) {
                $fields += $this->nested($value, $property, $label, $path);
                continue;
            }
            $text = match (true) {
                is_bool($value) => $value ? new Message('Yes') : new Message('No'),
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => self::text($value),
                default => null,
            };
            if ($text !== null && $text !== '') {
                $fields[$path] = [$label, $text];
            }
        }

        return $fields;
    }

    /**
     * A field that holds more than one value: blocks inside a block, a list of items (each a group of fields), a
     * list of values or a group of fields.
     *
     * @param array<array-key, mixed> $value
     * @param array<array-key, mixed> $property
     * @param list<Message>           $label
     *
     * @return array<string, array{list<Message>, string|Message}>
     */
    private function nested(array $value, array $property, array $label, string $path): array
    {
        if (!array_is_list($value)) {
            return $this->flatten($value, $property, $label, $path);
        }
        $fields = [];
        $scalars = [];
        $items = is_array($property['items'] ?? null) ? $property['items'] : [];
        $noun = Message::of(is_string($items['title'] ?? null) && $items['title'] !== '' ? $items['title'] : 'Item');
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                $scalars[] = is_bool($item) ? ($item ? 'yes' : 'no') : (is_scalar($item) ? self::plain((string) $item) : '');
                continue;
            }
            if (is_string($item['type'] ?? null) && is_array($item['data'] ?? null)) {
                $schema = $this->types->get($item['type'])?->schema() ?? [];
                $part = new Message('Block {index}: {label}', ['index' => $index + 1, 'label' => $this->label($item['type'])]);
                $fields += $this->flatten($item['data'], $schema, [...$label, $part], $path . '/' . $index);
                continue;
            }
            $fields += $this->flatten($item, $items, [...$label, new Message('{noun} {index}', ['noun' => $noun, 'index' => $index + 1])], $path . '/' . $index);
        }
        $scalars = array_values(array_filter($scalars, static fn (string $text): bool => $text !== ''));
        if ($scalars !== []) {
            $fields[$path] = [$label, implode(', ', $scalars)];
        }

        return $fields;
    }

    /**
     * Text as a reader sees it, or null when the value isn't text.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? self::plain($value) : null;
    }

    /**
     * Text as a reader sees it: rich text without its tags (a tag that ends a paragraph or a line becomes a space),
     * whitespace tidied.
     */
    private static function plain(string $value): string
    {
        if (str_contains($value, '<')) {
            $value = html_entity_decode(strip_tags((string) preg_replace('~<(?:br|/?(?:p|li|ul|ol|h[1-6]|blockquote|div|td|th|tr|table))\b[^>]*>~i', ' ', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function humanise(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
