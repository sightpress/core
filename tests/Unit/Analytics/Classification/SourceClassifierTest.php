<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Classification\SourceClassifier;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;

#[CoversClass(SourceClassifier::class)]
final class SourceClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sources(): iterable
    {
        yield 'no referrer' => ['/', '', 'direct'];
        yield 'a referrer that is not an address' => ['/', 'not a url', 'direct'];
        yield 'a referrer with a strange host' => ['/', 'https://exa mple.org/', 'direct'];
        yield 'the site itself' => ['/', 'https://demo.example/about?x=1', 'direct'];
        yield 'the site under www.' => ['/', 'https://www.demo.example/', 'direct'];
        yield 'Google in the UK' => ['/', 'https://www.google.co.uk/', 'search:Google'];
        yield 'Google in Portugal' => ['/', 'https://google.pt/search?q=secret', 'search:Google'];
        yield 'Bing' => ['/', 'https://www.bing.com/search?q=x', 'search:Bing'];
        yield 'DuckDuckGo' => ['/', 'https://duckduckgo.com/', 'search:DuckDuckGo'];
        yield 'Gemini, not Google' => ['/', 'https://gemini.google.com/app', 'ai:Gemini'];
        yield 'ChatGPT' => ['/', 'https://chatgpt.com/', 'ai:ChatGPT'];
        yield 'another site, host only and without www.' => ['/', 'https://WWW.Example.org./news/2026/story?id=7#top', 'site:example.org'];
        yield 'a site whose name only looks like Google' => ['/', 'https://notgoogle.com/', 'site:notgoogle.com'];
        yield 'utm_source first' => ['/?utm_source=Newsletter%20<b>Nov</b>&utm_medium=email', 'https://mail.example.net/', 'campaign:newsletter b nov b'];
        yield 'ref' => ['/?ref=mastodon.social', '', 'campaign:mastodon.social'];
        yield 'an empty label is ignored' => ['/?utm_source=%3C%3E', '', 'direct'];
    }

    #[DataProvider('sources')]
    public function testKeepsOnlyWhereTheVisitCameFrom(string $path, string $referer, string $source): void
    {
        $request = AnalyticsFixture::request($path, $referer === '' ? [] : ['Referer' => $referer]);

        self::assertSame($source, new SourceClassifier()->classify($request, ['demo.example', 'www.demo.example']));
    }

    public function testLabelsAreShortAndSafe(): void
    {
        self::assertSame(SourceClassifier::LABEL_LENGTH, mb_strlen(SourceClassifier::label(str_repeat('ä', 80))));
        self::assertSame('spring sale -2026', SourceClassifier::label("  Spring\t\"Sale\"-2026 "));
    }

    public function testTheListCanBeGiven(): void
    {
        $classifier = new SourceClassifier(['search' => ['Acme' => ['acme.test']]]);

        self::assertSame('search:Acme', $classifier->classify(AnalyticsFixture::request('/', ['Referer' => 'https://find.acme.test/']), []));
        self::assertSame('site:google.com', $classifier->classify(AnalyticsFixture::request('/', ['Referer' => 'https://google.com/']), []));
    }
}
