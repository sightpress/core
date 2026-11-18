<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Slug;

#[CoversClass(PageContent::class)]
#[UsesClass(Slug::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class PageContentTest extends TestCase
{
    public function testTidiesTheContentAndMakesTheSlugFromTheTitle(): void
    {
        $block = ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi', 'level' => 2]];
        $content = new PageContent("  About\n  us ", null, '  Who we are ', '', [$block, ['type' => 'acme.event_card', 'version' => 2, 'data' => []]]);

        self::assertSame(
            [
                'title' => 'About us',
                'slug' => 'about-us',
                'summary' => 'Who we are',
                'description' => null,
                'blocks' => [$block, ['type' => 'acme.event_card', 'version' => 2, 'data' => []]],
                'social_image' => null,
                'seo_title' => null,
                'noindex' => false,
                'canonical_url' => null,
            ],
            $content->toArray(),
        );
        self::assertSame('team', new PageContent('About', ' team ')->slug);
        self::assertSame('about', new PageContent('About', '  ')->slug);
    }

    /**
     * @return iterable<string, array{callable(): PageContent, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no title' => [static fn () => new PageContent('  '), 'Give the page a title.'];
        yield 'long title' => [static fn () => new PageContent(str_repeat('a', 201)), 'The title must be 200 characters or fewer.'];
        yield 'bad slug' => [static fn () => new PageContent('About', 'About Us'), 'The address "About Us" can only use'];
        yield 'long summary' => [static fn () => new PageContent('About', null, str_repeat('a', 301)), 'The summary must be 300 characters or fewer.'];
        yield 'long description' => [static fn () => new PageContent('About', null, null, str_repeat('a', 301)), 'The search engine description must be 300 characters or fewer.'];
        yield 'too many blocks' => [static fn () => new PageContent('About', null, null, null, array_fill(0, 501, ['type' => 'a', 'version' => 1, 'data' => []])), 'A page can have at most 500 blocks.'];
        foreach ([
            'not an array' => 'heading',
            'no type' => ['version' => 1, 'data' => []],
            'bad type' => ['type' => 'Heading', 'version' => 1, 'data' => []],
            'no version' => ['type' => 'heading', 'data' => []],
            'version 0' => ['type' => 'heading', 'version' => 0, 'data' => []],
            'no data' => ['type' => 'heading', 'version' => 1],
            'list data' => ['type' => 'heading', 'version' => 1, 'data' => ['a']],
            'extra key' => ['type' => 'heading', 'version' => 1, 'data' => [], 'html' => '<p>'],
        ] as $name => $block) {
            yield 'block ' . $name => [static fn () => new PageContent('About', null, null, null, [['type' => 'a', 'version' => 1, 'data' => []], $block]), 'Block 2 is not a block'];
        }
    }

    public function testKeepsWhatSearchEnginesGet(): void
    {
        $content = new PageContent('About', null, null, null, [], null, '  Meet  us ', true, ' https://example.org/original?a=1 ');

        self::assertSame(['Meet us', true, 'https://example.org/original?a=1'], [$content->seoTitle, $content->noindex, $content->canonicalUrl]);
        $copy = $content->withBlocks([]);
        self::assertSame(['Meet us', true, 'https://example.org/original?a=1'], [$copy->seoTitle, $copy->noindex, $copy->canonicalUrl]);
        self::assertNull(new PageContent('About', canonicalUrl: ' ')->canonicalUrl);
        foreach (['/about', 'ftp://example.org/x', 'https://', 'https://exa mple.org', 'https://example.org/' . str_repeat('a', 2000)] as $url) {
            try {
                new PageContent('About', canonicalUrl: $url);
                self::fail('Accepted ' . $url);
            } catch (PageException $exception) {
                self::assertSame('The original address must be a full web address starting with https:// or http://.', $exception->getMessage());
            }
        }
    }

    public function testTheShareImageMustBeAMediaId(): void
    {
        $this->expectExceptionMessage('The share image must be a file of the media library.');

        new PageContent('About', socialImage: 0);
    }

    public function testSwapsTheBlocks(): void
    {
        $content = new PageContent('About', 'about-us', 'Summary', 'Description', [], 12);

        $changed = $content->withBlocks([['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A', 'level' => 2]]]);

        self::assertSame(['About', 'about-us', 'Summary', 'Description'], [$changed->title, $changed->slug, $changed->summary, $changed->description]);
        self::assertSame([['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A', 'level' => 2]]], $changed->blocks);
        self::assertSame([], $content->blocks);
        self::assertSame(12, $changed->socialImage, 'the share image stays');
        self::assertSame(12, $changed->toArray()['social_image']);
    }

    /**
     * @param callable(): PageContent $make
     */
    #[DataProvider('invalid')]
    public function testRefusesContentThatBreaksTheRules(callable $make, string $message): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage($message);

        $make();
    }
}
