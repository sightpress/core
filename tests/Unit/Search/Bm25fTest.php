<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\Bm25f;

#[CoversClass(Bm25f::class)]
final class Bm25fTest extends TestCase
{
    public function testATitleMatchBeatsASummaryMatchBeatsABodyMatch(): void
    {
        $scorer = new Bm25f(10, [Bm25f::TITLE => 3.0, Bm25f::SUMMARY => 10.0, Bm25f::BODY => 100.0], ['river' => 3]);
        $lengths = [Bm25f::TITLE => 3, Bm25f::SUMMARY => 10, Bm25f::BODY => 100];

        $title = $scorer->score([['river']], ['river' => [Bm25f::TITLE => 1]], $lengths);
        $summary = $scorer->score([['river']], ['river' => [Bm25f::SUMMARY => 1]], $lengths);
        $body = $scorer->score([['river']], ['river' => [Bm25f::BODY => 1]], $lengths);

        self::assertGreaterThan($summary, $title);
        self::assertGreaterThan($body, $summary);
        self::assertGreaterThan(0.0, $body);
        self::assertGreaterThan($body, $scorer->score([['river']], ['river' => [Bm25f::BODY => 3]], $lengths), 'more often counts more');
        self::assertGreaterThan(
            $scorer->score([['river']], ['river' => [Bm25f::BODY => 1]], [Bm25f::BODY => 1000] + $lengths),
            $body,
            'in a shorter text it counts more',
        );
    }

    public function testRarerTermsCountMoreAndEachGroupCountsOnce(): void
    {
        $scorer = new Bm25f(100, [Bm25f::TITLE => 2.0, Bm25f::SUMMARY => 0.0, Bm25f::BODY => 0.0], ['rare' => 1, 'common' => 90, 'commons' => 50]);
        $lengths = [Bm25f::TITLE => 2];

        self::assertGreaterThan(
            $scorer->score([['common']], ['common' => [Bm25f::TITLE => 1]], $lengths),
            $scorer->score([['rare']], ['rare' => [Bm25f::TITLE => 1]], $lengths),
        );
        $both = ['common' => [Bm25f::TITLE => 1], 'commons' => [Bm25f::TITLE => 1]];
        self::assertSame(
            $scorer->score([['commons']], $both, $lengths),
            $scorer->score([['common', 'commons']], $both, $lengths),
            'the best term of a group',
        );
        self::assertSame(0.0, $scorer->score([['missing']], $both, $lengths));
        self::assertSame(0.0, $scorer->score([], $both, $lengths));
        self::assertGreaterThan(0.0, $scorer->score([['common']], ['common' => [Bm25f::SUMMARY => 1, 9 => 1]], []), 'no average length: no length normalisation');
    }
}
