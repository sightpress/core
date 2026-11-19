<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\Http\SearchExtract;

#[CoversClass(SearchExtract::class)]
final class SearchExtractTest extends TestCase
{
    public function testMarksTheMatchedWordsAsWholeWords(): void
    {
        self::assertSame(
            [['text' => 'The ', 'mark' => false], ['text' => 'River', 'mark' => true], ['text' => ' walk, by the riverside. ', 'mark' => false], ['text' => 'river', 'mark' => true], ['text' => ' <b>', 'mark' => false]],
            SearchExtract::of("The River walk,\n  by the riverside. river <b>", '', ['River', 'river', '', ' ']),
        );
        self::assertSame([['text' => 'Café ', 'mark' => false], ['text' => 'au lait', 'mark' => true]], SearchExtract::of('Café au lait', '', ['lait', 'au lait']), 'the longer words first');
        self::assertSame([['text' => 'No match here', 'mark' => false]], SearchExtract::of('No match here', '', ['zebra']));
        self::assertSame([['text' => 'Nothing matched', 'mark' => false]], SearchExtract::of('Nothing matched', '', []));
        self::assertSame([], SearchExtract::of(' ', '', ['river']));
        self::assertSame([['text' => '日本語', 'mark' => true], ['text' => 'のガラス', 'mark' => false]], SearchExtract::of('日本語のガラス', '', ['日本語']), 'words touch in Japanese');
    }

    public function testUsesTheBodyWhenTheSummaryHasNoMatch(): void
    {
        self::assertSame([['text' => 'Open every ', 'mark' => false], ['text' => 'day', 'mark' => true]], SearchExtract::of('Our hours', 'Open every day', ['day']));
        self::assertSame([['text' => 'Our hours', 'mark' => false]], SearchExtract::of('Our hours', 'Open every day', ['night']), 'no match anywhere: the summary');
        self::assertSame([['text' => 'Open every day', 'mark' => false]], SearchExtract::of('', 'Open every day', ['night']));
    }

    public function testShowsAboutTwoHundredAndFortyCharactersAroundTheFirstMatch(): void
    {
        $before = str_repeat('alpha ', 60);
        $after = str_repeat('omega ', 60);
        $pieces = SearchExtract::of('', $before . 'Harbour ' . $after, ['Harbour']);
        $text = implode('', array_column($pieces, 'text'));

        self::assertStringStartsWith('… alpha', $text);
        self::assertStringEndsWith('omega …', $text);
        self::assertLessThanOrEqual(SearchExtract::LENGTH + 4, mb_strlen($text));
        self::assertSame('Harbour', $pieces[1]['text']);
        self::assertTrue($pieces[1]['mark']);

        $start = implode('', array_column(SearchExtract::of('', 'Harbour ' . $after, ['Harbour']), 'text'));
        self::assertStringStartsWith('Harbour omega', $start, 'a match at the start: nothing left out before it');
        $end = implode('', array_column(SearchExtract::of('', $before . 'Harbour', ['Harbour']), 'text'));
        self::assertStringStartsWith('… alpha', $end);
        self::assertStringEndsWith('Harbour', $end, 'near the end: the last characters');
        $long = implode('', array_column(SearchExtract::of('', str_repeat('x', 300) . ' Harbour', ['nothing']), 'text'));
        self::assertSame(str_repeat('x', SearchExtract::LENGTH) . ' …', $long, 'no space to cut at');
        $spaceless = implode('', array_column(SearchExtract::of('', 'Harbour ' . str_repeat('y', 300), ['y']), 'text'));
        self::assertStringStartsWith('Harbour', $spaceless);
    }
}
