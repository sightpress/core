<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Psr\Clock\ClockInterface;
use SightPress\Core\Search\Analysis\TextAnalyzer;
use SightPress\Core\Search\Analysis\TextAnalyzers;
use SightPress\Core\Search\Analysis\Token;
use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchHit;
use SightPress\Module\Search\SearchIndexStatus;
use SightPress\Module\Search\SearchQuery;
use SightPress\Module\Search\SearchResults;
use SightPress\Module\Search\SearchVisibility;

/**
 * Core's search back-end (ADR-0011): an inverted index in the site database, written and read with the DBAL
 * QueryBuilder only, so it works the same on every supported database; analysis ({@see TextAnalyzer}) and scoring
 * ({@see Bm25f}) happen in PHP.
 *
 * A query uses at most {@see MAX_TERMS} words. Documents must have every word; when none has, those with any of them
 * are returned and the results say so. The last word also finds the longer words it starts ("accessib" finds
 * "accessibility"). Newer documents get a small boost. Only the query's locale is searched, and members-only
 * documents only for queries that may see them.
 */
final readonly class DatabaseSearchBackend implements SearchBackend
{
    public const string DOCUMENTS = 'search_document';
    public const string POSTINGS = 'search_posting';
    public const string WORDS = 'search_word';

    /** Most words of a query that are searched for; the rest are left out. */
    public const int MAX_TERMS = 10;

    /** Longest text kept from a document's body, in characters. */
    public const int MAX_BODY_LENGTH = 100_000;

    /** Most postings read for one query, so a very common word can't make a search slow. */
    public const int MAX_CANDIDATES = 50_000;

    /** Most longer words the last word of a query stands for. */
    public const int MAX_PREFIX_TERMS = 50;

    /** Most matched words given with each hit, for extracts. */
    public const int MAX_MATCHED_WORDS = 20;

    /** How much newer documents count more: up to 10 %, half of that after six months. */
    private const float RECENCY_BOOST = 0.1;

    private const float RECENCY_HALF_LIFE_DAYS = 180.0;

    /** Rows per statement when a list of ids or words goes into `IN (…)`. */
    private const int CHUNK = 500;

    public function __construct(
        private Connection $connection,
        private TextAnalyzers $analyzers,
        private ClockInterface $clock,
    ) {
    }

    public function index(SearchDocument $document): void
    {
        $analyzer = $this->analyzers->for($document->locale);
        $title = mb_substr($document->title, 0, 500);
        $body = mb_substr($document->body, 0, self::MAX_BODY_LENGTH);
        $parts = [
            Bm25f::TITLE => $analyzer->tokens($title),
            Bm25f::SUMMARY => $analyzer->tokens($document->summary),
            Bm25f::BODY => $analyzer->tokens($body),
        ];
        $postings = [];
        $words = [];
        foreach ($parts as $part => $tokens) {
            foreach ($tokens as $token) {
                $postings[$token->term][$part] = ($postings[$token->term][$part] ?? 0) + 1;
                $words[$token->normalised] = $token->term;
            }
        }

        $this->connection->transactional(function (Connection $connection) use ($document, $title, $body, $parts, $postings): void {
            $this->delete($this->ids($document->source, $document->ref, $document->locale));
            $connection->insert(self::DOCUMENTS, [
                'source' => $document->source,
                'ref' => $document->ref,
                'locale' => $document->locale,
                'url_path' => $document->path,
                'title' => $title,
                'summary' => $document->summary,
                'body' => $body,
                'breadcrumb' => json_encode($document->breadcrumb, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'visibility' => $document->visibility->value,
                'title_length' => count($parts[Bm25f::TITLE]),
                'summary_length' => count($parts[Bm25f::SUMMARY]),
                'body_length' => count($parts[Bm25f::BODY]),
                'updated_at' => $document->updatedAt,
                'indexed_at' => $this->clock->now(),
            ], ['updated_at' => Types::DATETIME_IMMUTABLE, 'indexed_at' => Types::DATETIME_IMMUTABLE]);
            $id = (int) $connection->lastInsertId();
            foreach ($postings as $term => $frequencies) {
                foreach ($frequencies as $part => $frequency) {
                    $connection->insert(self::POSTINGS, ['term' => (string) $term, 'document_id' => $id, 'part' => $part, 'frequency' => $frequency]);
                }
            }
        });
        $this->remember($document->locale, $words);
    }

    public function remove(string $source, string $ref, ?string $locale = null): void
    {
        $ids = $this->ids($source, $ref, $locale);
        if ($ids !== []) {
            $this->connection->transactional(fn (): null => $this->delete($ids));
        }
    }

    public function clear(?string $locale = null): void
    {
        $this->connection->transactional(function (Connection $connection) use ($locale): void {
            if ($locale === null) {
                foreach ([self::POSTINGS, self::DOCUMENTS, self::WORDS] as $table) {
                    $connection->createQueryBuilder()->delete($table)->executeStatement();
                }

                return;
            }
            $ids = array_map(self::int(...), $connection->createQueryBuilder()
                ->select('id')
                ->from(self::DOCUMENTS)
                ->where('locale = :locale')
                ->setParameter('locale', $locale)
                ->executeQuery()
                ->fetchFirstColumn());
            $this->delete($ids);
            $connection->createQueryBuilder()->delete(self::WORDS)->where('locale = :locale')->setParameter('locale', $locale)->executeStatement();
        });
    }

    public function query(SearchQuery $query): SearchResults
    {
        $analyzer = $this->analyzers->for($query->locale);
        $groups = $this->groups($query->locale, array_slice(self::distinct($analyzer->tokens($query->text)), 0, self::MAX_TERMS));
        if ($groups === []) {
            return new SearchResults(self::fit($query, 0));
        }
        $candidates = $this->candidates($query, array_values(array_unique(array_merge(...$groups))));
        if ($candidates === []) {
            return new SearchResults(self::fit($query, 0));
        }
        $matching = array_filter($candidates, static fn (array $candidate): bool => self::hasEveryGroup($groups, $candidate['terms']));
        $all = $matching !== [] || count($groups) === 1;
        $found = $all ? $matching : $candidates;

        $scorer = $this->scorer($query, $groups, $candidates);
        $now = $this->clock->now();
        $scores = [];
        foreach ($found as $id => $candidate) {
            $ageDays = max(0.0, ($now->getTimestamp() - $candidate['updated']) / 86400);
            $boost = 1.0 + self::RECENCY_BOOST * 0.5 ** ($ageDays / self::RECENCY_HALF_LIFE_DAYS);
            $scores[$id] = [$scorer->score($groups, $candidate['terms'], $candidate['lengths']) * $boost, $candidate['updated']];
        }
        uksort($scores, static fn (int $a, int $b): int => [$scores[$b][0], $scores[$b][1], $a] <=> [$scores[$a][0], $scores[$a][1], $b]);
        $query = self::fit($query, count($scores));
        $page = array_slice($scores, $query->offset(), $query->perPage, true);

        $hits = [];
        foreach ($this->documents(array_keys($page)) as $id => $document) {
            $hits[] = new SearchHit($document, $page[$id][0], self::matchedWords($analyzer, $document, array_keys($found[$id]['terms'])));
        }

        return new SearchResults($query, $hits, count($scores), $all);
    }

    public function status(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('locale', 'COUNT(id) AS documents', 'MAX(indexed_at) AS last_indexed')
            ->from(self::DOCUMENTS)
            ->groupBy('locale')
            ->orderBy('locale')
            ->executeQuery()
            ->fetchAllAssociative();

        $status = [];
        foreach ($rows as $row) {
            $last = $this->connection->convertToPHPValue($row['last_indexed'] ?? null, Types::DATETIME_IMMUTABLE);
            $status[] = new SearchIndexStatus(
                self::text($row['locale'] ?? null),
                self::int($row['documents'] ?? null),
                $last instanceof DateTimeImmutable ? $last : null,
            );
        }

        return $status;
    }

    /**
     * The query for the last page of results when it asks for a page after it (an old link, a changed index), since
     * results always answer a page that exists.
     */
    private static function fit(SearchQuery $query, int $total): SearchQuery
    {
        if ($query->page === 1 || $query->offset() < $total) {
            return $query;
        }

        return new SearchQuery($query->text, $query->locale, $query->includeMembersOnly, max(1, (int) ceil($total / $query->perPage)), $query->perPage);
    }

    /**
     * The query's words as groups of terms: one term per word, and for the last word also the terms of the longer
     * words it starts.
     *
     * @param list<Token> $tokens
     *
     * @return list<list<string>>
     */
    private function groups(string $locale, array $tokens): array
    {
        $groups = array_map(static fn (Token $token): array => [$token->term], $tokens);
        $last = end($tokens);
        if ($last === false || mb_strlen($last->normalised) < 2 || strpbrk($last->normalised, '%_\\') !== false) {
            return $groups;
        }
        $longer = $this->connection->createQueryBuilder()
            ->select('word', 'term')
            ->from(self::WORDS)
            ->where('locale = :locale')
            ->andWhere('word LIKE :prefix')
            ->setParameter('locale', $locale)
            ->setParameter('prefix', $last->normalised . '%')
            ->orderBy('word')
            ->setMaxResults(self::MAX_PREFIX_TERMS)
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($longer as $row) {
            $groups[count($groups) - 1][] = self::text($row['term'] ?? null);
        }
        $groups[count($groups) - 1] = array_values(array_unique($groups[count($groups) - 1]));

        return $groups;
    }

    /**
     * The documents that have any of the terms, with how often each term is in each part, the length of each part
     * and when the document last changed.
     *
     * @param list<string> $terms
     *
     * @return array<int, array{terms: array<string, array<int, int>>, lengths: array<int, int>, updated: int}>
     */
    private function candidates(SearchQuery $query, array $terms): array
    {
        $select = $this->connection->createQueryBuilder()
            ->select('p.document_id', 'p.term', 'p.part', 'p.frequency', 'd.title_length', 'd.summary_length', 'd.body_length', 'd.updated_at')
            ->from(self::POSTINGS, 'p')
            ->innerJoin('p', self::DOCUMENTS, 'd', 'd.id = p.document_id')
            ->where('d.locale = :locale')
            ->andWhere('p.term IN (:terms)')
            ->setParameter('locale', $query->locale)
            ->setParameter('terms', $terms, ArrayParameterType::STRING)
            ->setMaxResults(self::MAX_CANDIDATES);
        if (!$query->includeMembersOnly) {
            $select->andWhere('d.visibility = :public')->setParameter('public', SearchVisibility::Public->value);
        }

        $candidates = [];
        foreach ($select->executeQuery()->fetchAllAssociative() as $row) {
            $id = self::int($row['document_id'] ?? null);
            if (!isset($candidates[$id])) {
                $updated = $this->connection->convertToPHPValue($row['updated_at'] ?? null, Types::DATETIME_IMMUTABLE);
                $candidates[$id] = [
                    'terms' => [],
                    'lengths' => [
                        Bm25f::TITLE => self::int($row['title_length'] ?? null),
                        Bm25f::SUMMARY => self::int($row['summary_length'] ?? null),
                        Bm25f::BODY => self::int($row['body_length'] ?? null),
                    ],
                    'updated' => $updated instanceof DateTimeImmutable ? $updated->getTimestamp() : 0,
                ];
            }
            $candidates[$id]['terms'][self::text($row['term'] ?? null)][self::int($row['part'] ?? null)] = self::int($row['frequency'] ?? null);
        }

        return $candidates;
    }

    /**
     * @param list<list<string>>                                                              $groups
     * @param array<int, array{terms: array<string, array<int, int>>, lengths: array<int, int>, updated: int}> $candidates
     */
    private function scorer(SearchQuery $query, array $groups, array $candidates): Bm25f
    {
        $stats = $this->connection->createQueryBuilder()
            // Averages over the documents that have the part: most pages have no summary, and that shouldn't make a
            // summary count as long.
            ->select('COUNT(id) AS documents', 'AVG(NULLIF(title_length, 0)) AS title', 'AVG(NULLIF(summary_length, 0)) AS summary', 'AVG(NULLIF(body_length, 0)) AS body')
            ->from(self::DOCUMENTS)
            ->where('locale = :locale')
            ->setParameter('locale', $query->locale)
            ->executeQuery()
            ->fetchAssociative();
        $stats = $stats === false ? [] : $stats;
        $frequency = [];
        foreach ($candidates as $candidate) {
            foreach (array_keys($candidate['terms']) as $term) {
                $frequency[$term] = ($frequency[$term] ?? 0) + 1;
            }
        }
        $average = static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0;

        return new Bm25f(
            max(count($candidates), self::int($stats['documents'] ?? null)),
            [Bm25f::TITLE => $average($stats['title'] ?? null), Bm25f::SUMMARY => $average($stats['summary'] ?? null), Bm25f::BODY => $average($stats['body'] ?? null)],
            $frequency,
        );
    }

    /**
     * @param list<int> $ids the hits of a page of results, never empty
     *
     * @return array<int, SearchDocument> in the order of the ids
     */
    private function documents(array $ids): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'source', 'ref', 'locale', 'url_path', 'title', 'summary', 'body', 'breadcrumb', 'visibility', 'updated_at')
            ->from(self::DOCUMENTS)
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
        $found = [];
        foreach ($rows as $row) {
            $found[self::int($row['id'] ?? null)] = $row;
        }
        $documents = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $documents[$id] = $this->document($found[$id]);
            }
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function document(array $row): SearchDocument
    {
        try {
            $breadcrumb = json_decode(self::text($row['breadcrumb'] ?? null), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $breadcrumb = [];
        }
        $updated = $this->connection->convertToPHPValue($row['updated_at'] ?? null, Types::DATETIME_IMMUTABLE);

        return new SearchDocument(
            self::text($row['source'] ?? null),
            self::text($row['ref'] ?? null),
            self::text($row['locale'] ?? null),
            self::text($row['url_path'] ?? null),
            self::text($row['title'] ?? null),
            $updated instanceof DateTimeImmutable ? $updated : new DateTimeImmutable('@0'),
            self::text($row['summary'] ?? null),
            self::text($row['body'] ?? null),
            is_array($breadcrumb) ? array_values(array_filter($breadcrumb, static fn (mixed $crumb): bool => is_string($crumb) && trim($crumb) !== '')) : [],
            // Anything unexpected is treated as members only, so it is never shown to anonymous visitors.
            SearchVisibility::tryFrom(self::text($row['visibility'] ?? null)) ?? SearchVisibility::Members,
        );
    }

    /**
     * The words of the document whose terms matched, as they are written in it.
     *
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private static function matchedWords(TextAnalyzer $analyzer, SearchDocument $document, array $terms): array
    {
        $wanted = array_fill_keys($terms, true);
        $words = [];
        foreach ([$document->title, $document->summary, $document->body] as $text) {
            foreach ($analyzer->tokens($text) as $token) {
                if (isset($wanted[$token->term]) && !in_array($token->word, $words, true)) {
                    $words[] = $token->word;
                    if (count($words) === self::MAX_MATCHED_WORDS) {
                        return $words;
                    }
                }
            }
        }

        return $words;
    }

    /**
     * @param list<list<string>>              $groups
     * @param array<string, array<int, int>>  $terms
     */
    private static function hasEveryGroup(array $groups, array $terms): bool
    {
        foreach ($groups as $group) {
            if (array_intersect_key(array_flip($group), $terms) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<Token> the first token of each term
     */
    private static function distinct(array $tokens): array
    {
        $seen = [];
        $distinct = [];
        foreach ($tokens as $token) {
            if (!isset($seen[$token->term])) {
                $seen[$token->term] = true;
                $distinct[] = $token;
            }
        }

        return $distinct;
    }

    /**
     * The ids of the stored documents with a key, in one locale or in every locale.
     *
     * @return list<int>
     */
    private function ids(string $source, string $ref, ?string $locale): array
    {
        $select = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::DOCUMENTS)
            ->where('source = :source')
            ->andWhere('ref = :ref')
            ->setParameter('source', $source)
            ->setParameter('ref', $ref);
        if ($locale !== null) {
            $select->andWhere('locale = :locale')->setParameter('locale', $locale);
        }

        return array_values(array_map(self::int(...), $select->executeQuery()->fetchFirstColumn()));
    }

    /**
     * Deletes documents and their postings.
     *
     * @param list<int> $ids
     */
    private function delete(array $ids): null
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ([self::POSTINGS => 'document_id', self::DOCUMENTS => 'id'] as $table => $column) {
                $this->connection->createQueryBuilder()
                    ->delete($table)
                    ->where($column . ' IN (:ids)')
                    ->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                    ->executeStatement();
            }
        }

        return null;
    }

    /**
     * Adds the words the locale's vocabulary doesn't have yet, for prefix matches.
     *
     * @param array<string, string> $words normalised word => term
     */
    private function remember(string $locale, array $words): void
    {
        foreach (array_chunk(array_keys($words), self::CHUNK) as $chunk) {
            $chunk = array_map(strval(...), $chunk);
            $known = array_fill_keys(array_map(self::text(...), $this->connection->createQueryBuilder()
                ->select('word')
                ->from(self::WORDS)
                ->where('locale = :locale')
                ->andWhere('word IN (:words)')
                ->setParameter('locale', $locale)
                ->setParameter('words', $chunk, ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchFirstColumn()), true);
            foreach ($chunk as $word) {
                if (!isset($known[$word])) {
                    $this->connection->insert(self::WORDS, ['locale' => $locale, 'word' => $word, 'term' => $words[$word]]);
                }
            }
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
