<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Page\Admin\Editor\BlockFormView;
use SightPress\Core\Page\Admin\Editor\EditorBlocks;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\EveryFieldBlockType;

#[CoversClass(BlockFormView::class)]
#[CoversClass(EditorBlocks::class)]
#[UsesNamespace('SightPress\Core')]
final class BlockFormViewTest extends TestCase
{
    private BlockFormView $view;

    protected function setUp(): void
    {
        $fixture = new BlockFixture([new GalleryBlock(), new EveryFieldBlockType()]);
        $this->view = new BlockFormView($fixture->types, new MediaChoices(new MediaRepository($fixture->database->entityManager), new MediaTypes()));
    }

    public function testShowsEachBlockAsAFieldsetWithAControlPerField(): void
    {
        /** @var list<array{type: string, version: int, data: array<string, mixed>}> $blocks a broken block too, as a form could send it */
        $blocks = [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hello', 'level' => 3]],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 4, 'decorative' => true]],
            ['type' => 'gone.block', 'version' => 2, 'data' => ['keep' => 'me']],
            ['data' => 'nonsense'],
        ];
        $editor = $this->view->build('en-GB', $blocks, previews: ['/blocks/0' => '<p>Preview</p>']);
        $list = $editor->list;

        self::assertSame(['blocks', '/blocks', 'en-gb-blocks', 'blocks', 'Blocks', true, true], [self::get($list, 'kind'), self::get($list, 'pointer'), self::get($list, 'id'), self::get($list, 'name'), self::get($list, 'label'), self::get($list, 'can_add'), self::get($list, 'top_level')]);
        [$heading, $image, $gone, $nonsense] = self::items(self::get($list, 'blocks'));
        self::assertSame('Block 1 of 4: Heading', self::get($heading, 'legend'));
        self::assertSame('block 1, Heading', self::get($heading, 'short'));
        self::assertSame(['down', 'remove'], self::get($heading, 'moves'));
        self::assertSame('<p>Preview</p>', self::get($heading, 'preview'));
        self::assertNull(self::get($heading, 'kept'));
        [$text, $level] = self::items(self::get($heading, 'controls'));
        self::assertSame(['text', 'Heading text', 'blocks[0][data][text]', 'en-gb-blocks-0-data-text', 'Hello', true, 200], [self::get($text, 'kind'), self::get($text, 'label'), self::get($text, 'name'), self::get($text, 'id'), self::get($text, 'value'), self::get($text, 'required'), self::get($text, 'maxlength')]);
        self::assertSame(['select', '3', ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6']], [self::get($level, 'kind'), self::get($level, 'value'), self::get($level, 'choices')]);
        self::assertIsString(self::get($level, 'hint'));

        self::assertSame(['up', 'down', 'remove'], self::get($image, 'moves'));
        [$media, $alt, $decorative] = self::items(self::get($image, 'controls'));
        self::assertSame(['media', [4 => 'File 4 (no longer in the media library)']], [self::get($media, 'kind'), self::get($media, 'choices')]);
        self::assertSame(['', false], [self::get($alt, 'value'), self::get($alt, 'required')]);
        self::assertSame(['checkbox', true, ''], [self::get($decorative, 'kind'), self::get($decorative, 'checked'), self::get($decorative, 'value')]);

        self::assertSame('Block 3 of 4: Unknown block "gone.block"', self::get($gone, 'legend'));
        self::assertSame('{"keep":"me"}', self::get($gone, 'kept'));
        self::assertSame([], self::get($gone, 'controls'));
        self::assertSame(2, self::get($gone, 'version'));
        self::assertSame([1, 'Block 4 of 4: Unknown block ""', ['up', 'remove']], [self::get($nonsense, 'version'), self::get($nonsense, 'legend'), self::get($nonsense, 'moves')]);
        self::assertSame([], $editor->errors);
    }

    public function testListsInsideBlocksGetFieldsetsOfTheirOwn(): void
    {
        $list = $this->view->build('en-GB', [
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [['media' => 1]]]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [['blocks' => [['type' => 'heading', 'version' => 1, 'data' => []]]], ['blocks' => []]]]],
            ['type' => 'acme.every_field', 'version' => 1, 'data' => ['rows' => [['cells' => []], 'junk'], 'agreed' => true, 'colour' => 7]],
        ])->list;
        [$gallery, $columns, $every] = self::items(self::get($list, 'blocks'));

        $images = self::get($gallery, 'controls', 0);
        self::assertSame(['items', 'Picture', true, 'Pictures'], [self::get($images, 'kind'), self::get($images, 'noun'), self::get($images, 'can_add'), self::get($images, 'list_label')]);
        self::assertSame('Picture 1 of 1', self::get($images, 'items', 0, 'legend'));
        self::assertSame([], self::get($images, 'items', 0, 'moves'), 'a gallery keeps at least one picture');
        self::assertSame('blocks[0][data][images][0]', self::get($images, 'items', 0, 'name'));
        self::assertSame('picture 1', self::get($images, 'items', 0, 'short'));

        $columnList = self::get($columns, 'controls', 0);
        self::assertSame(['Column 1 of 2', ['down']], [self::get($columnList, 'items', 0, 'legend'), self::get($columnList, 'items', 0, 'moves')], 'columns keep at least two');
        $inside = self::get($columnList, 'items', 0, 'controls', 0);
        self::assertSame(['blocks', '/blocks/1/data/columns/0/blocks', false, true], [self::get($inside, 'kind'), self::get($inside, 'pointer'), self::get($inside, 'top_level'), self::get($inside, 'can_add')]);
        $choices = self::get($inside, 'add_choices');
        self::assertIsArray($choices);
        self::assertArrayNotHasKey('columns', $choices, 'no columns inside columns');
        self::assertSame('Block 1 of 1: Heading', self::get($inside, 'blocks', 0, 'legend'));

        $rows = self::get($every, 'controls', 6);
        self::assertSame(['Item', 'Item 1 of 2', ['down', 'remove']], [self::get($rows, 'noun'), self::get($rows, 'items', 0, 'legend'), self::get($rows, 'items', 0, 'moves')]);
        self::assertSame(['checkbox', true], [self::get($every, 'controls', 0, 'kind'), self::get($every, 'controls', 0, 'checked')]);
        self::assertSame('7', self::get($every, 'controls', 4, 'value'));
        self::assertSame(['red' => 'Red', 'blue' => 'Blue', '7' => '7'], self::get($every, 'controls', 4, 'choices'));
        self::assertSame(['email', 'number', 'number'], [self::get($every, 'controls', 5, 'kind'), self::get($every, 'controls', 1, 'kind'), self::get($every, 'controls', 3, 'kind')]);
    }

    public function testPutsEachErrorOnItsFieldOrTheNearestBlockAndListsThemInPageOrder(): void
    {
        $editor = $this->view->build('en-GB', [
            ['type' => 'heading', 'version' => 1, 'data' => []],
            ['type' => 'image', 'version' => 1, 'data' => []],
        ], [
            '/blocks/1/data/somewhere/deep' => 'Something is wrong with this image.',
            '/blocks/0/data/text' => 'Fill in this field.',
            '/elsewhere' => 'Something else.',
        ]);

        self::assertSame([
            ['id' => 'en-gb-blocks', 'message' => 'Blocks: Something else.'],
            ['id' => 'en-gb-blocks-0-data-text', 'message' => 'Heading text: Fill in this field.'],
            ['id' => 'en-gb-blocks-1', 'message' => 'Block 2 of 2: Image: Something is wrong with this image.'],
        ], $editor->errors);
        self::assertIsArray(self::get($editor->list, 'blocks'));
        self::assertSame(['Fill in this field.'], self::get($editor->list, 'blocks', 0, 'controls', 0, 'errors'));
        self::assertSame(['Something is wrong with this image.'], self::get($editor->list, 'blocks', 1, 'errors'));
    }

    public function testOffersTheBlockTypesInAlphabeticalOrder(): void
    {
        $top = $this->view->addable(true);

        self::assertSame(['call_to_action', 'columns', 'acme.every_field', 'gallery', 'heading', 'image', 'rich_text'], array_keys($top));
        self::assertArrayNotHasKey('columns', $this->view->addable(false));
        self::assertArrayHasKey('acme.every_field', $this->view->addable(false));
    }

    /**
     * The value at the keys of a view tree.
     */
    private static function get(mixed $tree, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($tree);
            self::assertArrayHasKey($key, $tree);
            $tree = $tree[$key];
        }

        return $tree;
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $list): array
    {
        self::assertIsArray($list);

        return array_values($list);
    }
}
