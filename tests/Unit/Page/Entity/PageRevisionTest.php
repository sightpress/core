<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Slug;
use SightPress\Core\Tenant\LocaleCode;

#[CoversClass(PageRevision::class)]
#[UsesClass(Page::class)]
#[UsesClass(PageTranslation::class)]
#[UsesClass(PageContent::class)]
#[UsesClass(Slug::class)]
#[UsesClass(LocaleCode::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class PageRevisionTest extends TestCase
{
    public function testKeepsACopyOfThePageAsItWas(): void
    {
        $now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
        $page = new Page(null, 0, 4, $now);
        $page->write('en-GB', new PageContent('About'), 'about', $now);

        $revision = new PageRevision($page, 1, 4, $now);
        $page->write('en-GB', new PageContent('Changed'), 'changed', $now);

        self::assertNull($revision->id());
        self::assertSame([$page, 1, 4, $now], [$revision->page(), $revision->number(), $revision->savedBy(), $revision->createdAt()]);
        $snapshot = $revision->snapshot();
        self::assertIsArray($snapshot['translations']);
        self::assertIsArray($snapshot['translations']['en-GB']);
        self::assertSame('About', $snapshot['translations']['en-GB']['title']);
    }

    public function testIsNumberedFromOne(): void
    {
        $now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('Revisions are numbered from 1.');

        new PageRevision(new Page(null, 0, null, $now), 0, null, $now);
    }
}
