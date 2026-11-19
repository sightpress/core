<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\Analysis\TextAnalyzers;
use SightPress\Core\Search\DatabaseSearchBackend;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchHit;
use SightPress\Module\Search\SearchQuery;
use SightPress\Module\Search\SearchVisibility;

#[CoversClass(DatabaseSearchBackend::class)]
#[UsesNamespace('SightPress\Core\Search')]
#[UsesNamespace('SightPress\Core')]
final class DatabaseSearchBackendTest extends TestCase
{
    private CoreSiteDatabase $database;

    private FixedClock $clock;

    private DatabaseSearchBackend $backend;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->clock = new FixedClock();
        $this->backend = new DatabaseSearchBackend($this->database->connection, new TextAnalyzers(), $this->clock);
    }

    public function testATitleMatchComesBeforeABodyMatch(): void
    {
        $this->backend->index(self::document('1', 'Opening hours', body: 'The library by the river.'));
        $this->backend->index(self::document('2', 'Our river walks', body: 'Walks along the water.'));
        $this->backend->index(self::document('3', 'Events', summary: 'Talks by the river', body: 'Talks and readings.'));

        $results = $this->backend->query(new SearchQuery('river', 'en-GB'));

        self::assertSame(['2', '3', '1'], self::refs($results->hits));
        self::assertSame(3, $results->total);
        self::assertTrue($results->matchedAllTerms);
        self::assertSame(['river'], $results->hits[0]->matchedWords);
        self::assertSame('/page-2', $results->hits[0]->document->path);
        self::assertSame(['Home', 'About'], $results->hits[0]->document->breadcrumb);
        self::assertSame('2026-11-01 09:00:00', $results->hits[0]->document->updatedAt->format('Y-m-d H:i:s'));
    }

    public function testEveryWordFirstThenAnyWord(): void
    {
        $this->backend->index(self::document('1', 'Library opening hours'));
        $this->backend->index(self::document('2', 'Library events'));
        $this->backend->index(self::document('3', 'Opening of the new wing'));

        $all = $this->backend->query(new SearchQuery('library opening', 'en-GB'));
        self::assertSame(['1'], self::refs($all->hits));
        self::assertTrue($all->matchedAllTerms);

        $any = $this->backend->query(new SearchQuery('library concerts', 'en-GB'));
        self::assertFalse($any->matchedAllTerms);
        self::assertEqualsCanonicalizing(['1', '2'], self::refs($any->hits));

        self::assertTrue($this->backend->query(new SearchQuery('nothing here', 'en-GB'))->isEmpty());
        self::assertTrue($this->backend->query(new SearchQuery('the of', 'en-GB'))->isEmpty(), 'only stop words');
    }

    public function testTheLastWordAlsoFindsLongerWords(): void
    {
        $this->backend->index(self::document('1', 'Accessibility statement'));
        $this->backend->index(self::document('2', 'Accessible routes', body: 'A statement about routes'));
        $this->backend->index(self::document('3', 'Snake_case names'));

        $results = $this->backend->query(new SearchQuery('statement accessib', 'en-GB'));
        self::assertEqualsCanonicalizing(['1', '2'], self::refs($results->hits));
        self::assertTrue($results->matchedAllTerms);
        self::assertContains('Accessibility', $results->hits[0]->matchedWords + $results->hits[1]->matchedWords);

        self::assertFalse($this->backend->query(new SearchQuery('accessib statement', 'en-GB'))->matchedAllTerms, 'only the last word');
        self::assertSame([], self::refs($this->backend->query(new SearchQuery('snake_c', 'en-GB'))->hits), 'no LIKE wildcards from the query');
        self::assertSame(['3'], self::refs($this->backend->query(new SearchQuery('snake_case', 'en-GB'))->hits));
        self::assertSame([], self::refs($this->backend->query(new SearchQuery('a', 'pl'))->hits), 'one letter is too short to stand for longer words');
    }

    public function testSearchesOneLocaleAndMembersDocumentsOnlyWhenAllowed(): void
    {
        $this->backend->index(self::document('1', 'River walks'));
        $this->backend->index(self::document('1', 'Passeios pelo rio', locale: 'pt-PT'));
        $this->backend->index(self::document('2', 'River club', visibility: SearchVisibility::Members));

        self::assertSame(['1'], self::refs($this->backend->query(new SearchQuery('river', 'en-GB'))->hits));
        self::assertEqualsCanonicalizing(['1', '2'], self::refs($this->backend->query(new SearchQuery('river', 'en-GB', includeMembersOnly: true))->hits));
        $portuguese = $this->backend->query(new SearchQuery('rio', 'pt-PT'));
        self::assertSame(['1'], self::refs($portuguese->hits));
        self::assertSame('pt-PT', $portuguese->hits[0]->document->locale);
        self::assertTrue($this->backend->query(new SearchQuery('river', 'pt-PT'))->isEmpty());
    }

    public function testIndexingAgainReplacesTheDocumentAndRemoveTakesItOut(): void
    {
        $this->backend->index(self::document('1', 'River walks'));
        $this->backend->index(self::document('1', 'Mountain walks'));
        $this->backend->index(self::document('1', 'Passeios', locale: 'pt-PT'));
        $this->backend->index(self::document('2', 'River club'));

        self::assertSame(['2'], self::refs($this->backend->query(new SearchQuery('river', 'en-GB'))->hits));
        self::assertSame(['1'], self::refs($this->backend->query(new SearchQuery('mountain', 'en-GB'))->hits));
        self::assertSame(3, $this->rows(DatabaseSearchBackend::DOCUMENTS));

        $this->backend->remove('page', '1', 'en-GB');
        self::assertTrue($this->backend->query(new SearchQuery('mountain', 'en-GB'))->isEmpty());
        self::assertFalse($this->backend->query(new SearchQuery('passeios', 'pt-PT'))->isEmpty());
        $this->backend->remove('page', '1');
        $this->backend->remove('page', '404');
        self::assertTrue($this->backend->query(new SearchQuery('passeios', 'pt-PT'))->isEmpty());
        self::assertSame(1, $this->rows(DatabaseSearchBackend::DOCUMENTS));
        self::assertSame(2, $this->rows(DatabaseSearchBackend::POSTINGS), 'river + club');
    }

    public function testClearsOneLocaleOrEverything(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-11-14 12:00:00 UTC'));
        $this->backend->index(self::document('1', 'River walks'));
        $this->backend->index(self::document('2', 'River club'));
        $this->backend->index(self::document('1', 'Passeios', locale: 'pt-PT'));

        $status = $this->backend->status();
        self::assertSame(['en-GB', 'pt-PT'], array_map(static fn ($entry) => $entry->locale, $status));
        self::assertSame([2, 1], array_map(static fn ($entry) => $entry->documents, $status));
        self::assertSame('2026-11-14 12:00', $status[0]->lastIndexedAt?->format('Y-m-d H:i'));

        $this->backend->clear('pt-PT');
        self::assertSame(['en-GB'], array_map(static fn ($entry) => $entry->locale, $this->backend->status()));
        self::assertSame(0, $this->rows(DatabaseSearchBackend::WORDS, 'pt-PT'));
        self::assertGreaterThan(0, $this->rows(DatabaseSearchBackend::WORDS, 'en-GB'));

        $this->backend->clear();
        self::assertSame([], $this->backend->status());
        foreach ([DatabaseSearchBackend::DOCUMENTS, DatabaseSearchBackend::POSTINGS, DatabaseSearchBackend::WORDS] as $table) {
            self::assertSame(0, $this->rows($table));
        }
    }

    public function testPagesOfResultsAndNewerDocumentsFirstWhenEqual(): void
    {
        foreach (range(1, 12) as $number) {
            $this->backend->index(self::document((string) $number, 'Concert', updatedAt: new DateTimeImmutable(sprintf('2026-%02d-01 09:00:00 UTC', $number))));
        }

        $first = $this->backend->query(new SearchQuery('concert', 'en-GB', perPage: 5));
        self::assertSame(['12', '11', '10', '9', '8'], self::refs($first->hits));
        self::assertSame(12, $first->total);
        self::assertSame(3, $first->pageCount());
        self::assertSame(['2', '1'], self::refs($this->backend->query(new SearchQuery('concert', 'en-GB', page: 3, perPage: 5))->hits));
        $after = $this->backend->query(new SearchQuery('concert', 'en-GB', page: 4, perPage: 5));
        self::assertSame(3, $after->query->page, 'a page after the last one gives the last one');
        self::assertSame(['2', '1'], self::refs($after->hits));
        $none = $this->backend->query(new SearchQuery('nothing', 'en-GB', page: 2));
        self::assertSame(1, $none->query->page);
        self::assertSame(1, $this->backend->query(new SearchQuery('the', 'en-GB', page: 2))->query->page);
    }

    public function testAQueryUsesAtMostTenWords(): void
    {
        $this->backend->index(self::document('1', 'one two three four five six seven eight nine ten'));
        $this->backend->index(self::document('2', 'eleven'));

        $results = $this->backend->query(new SearchQuery('one two three four five six seven eight nine ten eleven one', 'en-GB'));

        self::assertSame(['1'], self::refs($results->hits), 'the eleventh word is left out, the repeated one counts once');
        self::assertTrue($results->matchedAllTerms);
    }

    public function testGivesAtMostTwentyMatchedWordsAsWritten(): void
    {
        $words = array_map(static fn (int $n): string => 'Walk' . str_repeat('s', $n % 2) . ' ', range(1, 30));
        $this->backend->index(self::document('1', 'Walking', body: implode('', $words) . implode(' ', array_map(static fn (int $n): string => 'walker' . $n, range(1, 30)))));

        $hit = $this->backend->query(new SearchQuery('walk', 'en-GB'))->hits[0];

        self::assertSame(['Walking', 'Walks', 'Walk'], array_slice($hit->matchedWords, 0, 3));
        self::assertLessThanOrEqual(DatabaseSearchBackend::MAX_MATCHED_WORDS, count($hit->matchedWords));
    }

    public function testKeepsLongTextsShortAndReadsOddRowsSafely(): void
    {
        $this->backend->index(self::document('1', str_repeat('T', 600), body: str_repeat('word ', 30_000)));
        $row = $this->database->connection->createQueryBuilder()->select('title', 'body')->from(DatabaseSearchBackend::DOCUMENTS)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['title']);
        self::assertIsString($row['body']);
        self::assertSame(500, mb_strlen($row['title']));
        self::assertSame(DatabaseSearchBackend::MAX_BODY_LENGTH, mb_strlen($row['body']));

        $this->database->connection->update(DatabaseSearchBackend::DOCUMENTS, ['breadcrumb' => '{broken', 'visibility' => 'secret'], ['ref' => '1']);
        self::assertTrue($this->backend->query(new SearchQuery('word', 'en-GB'))->isEmpty(), 'an unknown visibility is members only');
        $hit = $this->backend->query(new SearchQuery('word', 'en-GB', includeMembersOnly: true))->hits[0] ?? null;
        self::assertNotNull($hit);
        self::assertSame([], $hit->document->breadcrumb);
        self::assertSame(SearchVisibility::Members, $hit->document->visibility);
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return list<string>
     */
    private static function refs(array $hits): array
    {
        return array_map(static fn (SearchHit $hit): string => $hit->document->ref, $hits);
    }

    private function rows(string $table, ?string $locale = null): int
    {
        $query = $this->database->connection->createQueryBuilder()->select('COUNT(*)')->from($table);
        if ($locale !== null) {
            $query->where('locale = :locale')->setParameter('locale', $locale);
        }

        $count = $query->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    private static function document(
        string $ref,
        string $title,
        string $summary = '',
        string $body = '',
        string $locale = 'en-GB',
        SearchVisibility $visibility = SearchVisibility::Public,
        ?DateTimeImmutable $updatedAt = null,
    ): SearchDocument {
        return new SearchDocument('page', $ref, $locale, '/page-' . $ref, $title, $updatedAt ?? new DateTimeImmutable('2026-11-01 09:00:00 UTC'), $summary, $body, ['Home', 'About'], $visibility);
    }
}
