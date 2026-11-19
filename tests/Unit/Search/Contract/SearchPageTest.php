<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\Contract\SearchPage;

#[CoversClass(SearchPage::class)]
final class SearchPageTest extends TestCase
{
    public function testTheResultsPageIsInEachLanguage(): void
    {
        self::assertSame('/search', SearchPage::url('en-GB', 'en-GB'));
        self::assertSame('/pt-pt/search', SearchPage::url('pt-PT', 'en-GB'));
    }
}
