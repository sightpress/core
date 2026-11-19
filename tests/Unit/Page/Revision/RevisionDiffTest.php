<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Revision;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Block\Type\ImageBlock;
use SightPress\Core\Block\Type\RichTextBlock;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Page\Revision\DiffRow;
use SightPress\Core\Page\Revision\DiffSection;
use SightPress\Core\Page\Revision\RevisionDiff;
use SightPress\Core\Template\Message;

#[CoversClass(RevisionDiff::class)]
#[CoversClass(DiffRow::class)]
#[CoversClass(DiffSection::class)]
#[UsesNamespace('SightPress\Core')]
final class RevisionDiffTest extends TestCase
{
    private RevisionDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new RevisionDiff(new BlockTypeCatalogue(array_map(
            static fn (object $type): array => ['module' => 'test', 'type' => $type],
            [new HeadingBlock(), new RichTextBlock(), new ImageBlock(), new ColumnsBlock(), new GalleryBlock()],
        )));
    }

    public function testComparesTheDetailsOfThePageWordByWord(): void
    {
        $old = self::translation(['title' => 'About us', 'summary' => 'Who we are', 'noindex' => true, 'social_image' => 4]);
        $new = self::translation(['title' => 'About our team', 'path' => 'about-team', 'description' => 'Our people', 'draft' => true, 'social_image' => 5, 'exclude_from_site_search' => true]);

        $sections = $this->diff->compare($old, $new);

        self::assertCount(1, $sections);
        self::assertSame(['About the page', 'changed'], [(string) $sections[0]->heading, $sections[0]->status]);
        self::assertSame([
            ['Title', 'changed', [['same', 'About'], ['removed', 'us'], ['added', 'our team']]],
            ['Address', 'changed', [['removed', '/about'], ['added', '/about-team']]],
            ['Description for search engines', 'added', [['added', 'Our people']]],
            ['Picture shown when the page is shared', 'changed', [['removed', 'File number {id} of the media library'], ['added', 'File number {id} of the media library']]],
            ['Leave this page out of the site’s own search', 'added', [['added', 'Yes']]],
            ['This translation is a draft of its own', 'added', [['added', 'Yes']]],
            ['Summary', 'removed', [['removed', 'Who we are']]],
            ['Ask search engines and AI search engines not to list this page', 'removed', [['removed', 'Yes']]],
        ], self::rows($sections[0]));
        self::assertFalse(RevisionDiff::isUnchanged($sections));
    }

    public function testAFirstVersionIsAllNewAndASameOneUnchanged(): void
    {
        $page = self::translation(['blocks' => [self::heading('Welcome')]]);

        $first = $this->diff->compare(null, $page);
        self::assertSame(['added', 'added'], array_map(static fn (DiffSection $section): string => $section->status, $first));
        self::assertSame('Block {index} of {count}: {label}', $first[1]->heading->text);
        self::assertSame([['Heading text', 'added', [['added', 'Welcome']]], ['Level', 'added', [['added', '2']]]], self::rows($first[1]));

        $same = $this->diff->compare($page, self::translation(['blocks' => [self::heading('Welcome')], 'summary' => '  ']));
        self::assertSame(['same', 'same'], array_map(static fn (DiffSection $section): string => $section->status, $same));
        self::assertTrue(RevisionDiff::isUnchanged($same));

        $gone = $this->diff->compare($page, null);
        self::assertSame(['removed', 'removed'], array_map(static fn (DiffSection $section): string => $section->status, $gone));
        self::assertSame('Removed block: {label}', $gone[1]->heading->text);
    }

    public function testPairsBlocksThatStayedAndComparesTheOnesThatChanged(): void
    {
        $old = self::translation(['blocks' => [
            self::heading('Intro'),
            self::text('<p>Hello <strong>old</strong> world</p><p>Second&nbsp;line</p>'),
            self::heading('Gone'),
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 3, 'alt' => 'A cat', 'decorative' => false]],
            'not a block',
        ]]);
        $new = self::translation(['blocks' => [
            self::heading('Intro'),
            self::text('<p>Hello new world</p><p>Second line</p>'),
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 3, 'alt' => 'A black cat', 'decorative' => true]],
            self::heading('Outro'),
        ]]);

        $sections = $this->diff->compare($old, $new);

        self::assertSame(
            [['About the page', 'same'], ['Block {index} of {count}: {label}', 'same'], ['Block {index} of {count}: {label}', 'changed'], ['Removed block: {label}', 'removed'], ['Block {index} of {count}: {label}', 'changed'], ['Block {index} of {count}: {label}', 'added']],
            array_map(static fn (DiffSection $section): array => [$section->heading->text, $section->status], $sections),
        );
        self::assertSame(['index' => '2', 'count' => '4', 'label' => 'Text'], array_map(strval(...), $sections[2]->heading->parameters));
        self::assertSame([['Text', 'changed', [['same', 'Hello'], ['removed', 'old'], ['added', 'new'], ['same', 'world Second line']]]], self::rows($sections[2]));
        self::assertSame([
            ['Alternative text', 'changed', [['same', 'A'], ['added', 'black'], ['same', 'cat']]],
            ['The picture is only decoration: screen readers skip it', 'changed', [['removed', 'No'], ['added', 'Yes']]],
        ], self::rows($sections[4]));
    }

    public function testLooksInsideColumnsListsAndUnknownBlocks(): void
    {
        $columns = static fn (string $left, string $right): array => ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
            ['blocks' => [self::heading($left)]],
            ['blocks' => [self::text('<p>' . $right . '</p>')]],
        ]]];
        $old = self::translation(['blocks' => [
            $columns('Left', 'Right'),
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [['media' => 1, 'alt' => 'One']], 'caption' => 'Trip']],
            ['type' => 'acme.map', 'version' => 1, 'data' => ['place_name' => 'Lisbon', 'tags' => ['a', 'b'], 'flags' => [true, false], 'point' => ['lat' => 38.7, 'zoom' => null], 'empty' => '']],
        ]]);
        $new = self::translation(['blocks' => [
            $columns('Left side', 'Right'),
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [['media' => 1, 'alt' => 'Uno']], 'caption' => 'Trip']],
            ['type' => 'acme.map', 'version' => 1, 'data' => ['place_name' => 'Porto', 'tags' => ['a', 'c', ''], 'flags' => [false], 'point' => ['lat' => 41.1]]],
        ]]);

        $sections = $this->diff->compare($old, $new);

        self::assertSame([['Columns › Column 1 › Blocks › Block 1: Heading › Heading text', 'changed', [['same', 'Left'], ['added', 'side']]]], self::rows($sections[1]));
        self::assertSame([['Pictures › Picture 1 › Alternative text', 'changed', [['removed', 'One'], ['added', 'Uno']]]], self::rows($sections[2]));
        self::assertSame('acme.map', (string) $sections[3]->heading->parameters['label']);
        self::assertSame([
            ['Place name', 'changed', [['removed', 'Lisbon'], ['added', 'Porto']]],
            ['Tags', 'changed', [['same', 'a,'], ['removed', 'b'], ['added', 'c']]],
            ['Flags', 'changed', [['removed', 'yes,'], ['same', 'no']]],
            ['Point › Lat', 'changed', [['removed', '38.7'], ['added', '41.1']]],
        ], self::rows($sections[3]));
    }

    public function testItemsWithoutATitleAndBlocksWithoutData(): void
    {
        $diff = new RevisionDiff(new BlockTypeCatalogue());
        $sections = $diff->compare(null, self::translation(['blocks' => [
            ['type' => 'acme.list', 'data' => ['rows' => [['name' => 'A'], 'x'], 'weird' => [new \stdClass()]]],
            ['type' => 'acme.bare'],
        ]]));

        self::assertSame([
            ['Rows › Item 1 › Name', 'added', [['added', 'A']]],
            ['Rows', 'added', [['added', 'x']]],
        ], self::rows($sections[1]));
        self::assertSame([], self::rows($sections[2]));
        self::assertSame([], $diff->compare(['title' => 'x', 'blocks' => 'nope'], ['title' => 'x'])[0]->rows);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function translation(array $fields): array
    {
        return $fields + ['title' => 'About', 'slug' => 'about', 'path' => 'about', 'summary' => null, 'description' => null, 'blocks' => [], 'social_image' => null, 'seo_title' => null, 'noindex' => false, 'canonical_url' => null, 'exclude_from_site_search' => false, 'draft' => false];
    }

    /**
     * @return array{type: string, version: int, data: array<string, mixed>}
     */
    private static function heading(string $text): array
    {
        return ['type' => 'heading', 'version' => 1, 'data' => ['text' => $text, 'level' => 2]];
    }

    /**
     * @return array{type: string, version: int, data: array<string, mixed>}
     */
    private static function text(string $html): array
    {
        return ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]];
    }

    /**
     * @return list<array{string, string, list<array{string, string}>}>
     */
    private static function rows(DiffSection $section): array
    {
        return array_map(static fn (DiffRow $row): array => [
            implode(' › ', array_map(strval(...), $row->label)),
            $row->status,
            array_map(static fn (array $part): array => [$part[0], $part[1] instanceof Message ? $part[1]->text : $part[1]], $row->parts),
        ], $section->rows);
    }
}
