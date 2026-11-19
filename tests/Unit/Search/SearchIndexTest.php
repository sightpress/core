<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tests\Support\Search\RecordingSearchBackend;
use SightPress\Core\Tests\Support\Search\SearchFixture;
use SightPress\Module\Search\SearchQuery;

#[CoversClass(SearchIndex::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchIndexTest extends TestCase
{
    public function testReindexBuildsTheIndexFromEveryProviderAndCanRunAgain(): void
    {
        $search = new SearchFixture();
        $search->provider->add('1', 'Book fair');
        $search->provider->add('2', 'Poetry night');
        $search->provider->add('1', 'Feira do livro', 'pt-PT');
        $search->provider->add('9', 'Fête', 'fr');
        $index = $search->index();
        self::assertFalse($index->isReady());

        self::assertSame(['en-GB' => 2, 'pt-PT' => 1], $index->reindex());
        self::assertSame(['en-GB' => 2, 'pt-PT' => 1], $index->reindex(), 'idempotent');
        self::assertTrue($index->isReady());
        self::assertSame(2, $search->backend->status()[0]->documents);
        self::assertSame('/events/1', $index->backend()->query(new SearchQuery('book', 'en-GB'))->hits[0]->document->path);

        $search->provider->add('3', 'Livros raros', 'pt-PT');
        $index->markOutOfDate();
        self::assertFalse($index->isReady());
        self::assertSame(['pt-PT' => 2], $index->reindex('pt-PT'));
        self::assertFalse($index->isReady(), 'only a whole reindex makes it ready');
        self::assertSame(2, $search->backend->status()[0]->documents, 'other locales are kept');
    }

    public function testRefreshIndexesWhatIsThereAndRemovesWhatIsGone(): void
    {
        $search = new SearchFixture(new RecordingSearchBackend());
        $search->provider->add('1', 'Book fair');
        $search->provider->add('1', 'Feira do livro', 'pt-PT');
        $index = $search->index();

        $index->refresh('acme.events', ['1']);
        $index->refresh('acme.events', ['2'], 'pt-PT');
        $index->refresh('acme.news', ['1']);
        $index->refresh('acme.events', []);
        $index->remove('acme.events', ['1']);
        $index->forget('pt-PT');

        self::assertSame(
            ['index acme.events:1:en-GB', 'index acme.events:1:pt-PT', 'remove acme.events:2:pt-PT', 'remove acme.events:1:*', 'clear pt-PT'],
            $search->replacement?->calls,
        );
        self::assertSame([], $search->backend->status(), 'core\'s back-end is left alone when a module replaced it');
    }
}
