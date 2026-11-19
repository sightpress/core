<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Search\SearchReindexJob;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\Search\SearchFixture;

#[CoversClass(SearchReindexJob::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchReindexJobTest extends TestCase
{
    public function testBuildsTheIndexWhenItIsNotReady(): void
    {
        $search = new SearchFixture();
        $search->provider->add('1', 'Book fair');
        $index = $search->index();
        $job = new SearchReindexJob();
        $due = static fn (): ScheduleDue => new ScheduleDue($search->site, new ArrayContainer([SearchIndex::class => $index]), new DateTimeImmutable());

        $job($first = $due());
        self::assertSame(['Site search: indexed 1 document.'], $first->lines());
        self::assertTrue($index->isReady());

        $job($second = $due());
        self::assertSame([], $second->lines(), 'nothing to do');

        $search->provider->add('2', 'Poetry night');
        $index->markOutOfDate();
        $job($third = $due());
        self::assertSame(['Site search: indexed 2 documents.'], $third->lines());
    }
}
