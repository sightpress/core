<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Revision;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Revision\RevisionHistory;
use SightPress\Core\Page\Revision\RevisionVersion;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(RevisionHistory::class)]
#[CoversClass(RevisionVersion::class)]
#[UsesNamespace('SightPress\Core')]
final class RevisionHistoryTest extends TestCase
{
    public function testListsTheRevisionsInWhichATranslationChangedNewestFirst(): void
    {
        $fixture = new PageFixture();
        $editor = $fixture->editor();
        $page = $editor->create('en-GB', new PageContent('About'), null, 3);                // 1
        $editor->save($page, 'pt-PT', new PageContent('Sobre', 'sobre'));                   // 2: pt-PT only
        $page->changeState(PageState::Published, $fixture->clock->now());
        $editor->save($page, 'en-GB', new PageContent('About us', 'about'), 4);              // 3
        $editor->save($page, 'en-GB', new PageContent('About us', 'about'), 4);              // 4: nothing new
        $history = new RevisionHistory($fixture->repository());

        $english = $history->versions($page, 'en-GB');
        self::assertSame([3, 1], array_map(static fn (RevisionVersion $version): int => $version->revision->number(), $english));
        self::assertSame([PageState::Published, PageState::Draft], array_map(static fn (RevisionVersion $version): ?PageState => $version->state, $english));
        self::assertSame(2, $english[0]->previous?->number());
        self::assertNull($english[1]->previous);

        $portuguese = $history->versions($page, 'pt-PT');
        self::assertSame([2], array_map(static fn (RevisionVersion $version): int => $version->revision->number(), $portuguese));
        self::assertNull($portuguese[0]->previous);
        self::assertSame([], $history->versions($page, 'es'));

        self::assertSame(3, $history->version($page, 'pt-PT', 4)?->previous?->number());
        self::assertNull($history->version($page, 'en-GB', 1)?->previous);
        self::assertNull($history->version($page, 'en-GB', 9));
        self::assertSame('Sobre', RevisionHistory::translation(($history->version($page, 'pt-PT', 2) ?? self::fail('no version'))->revision, 'pt-PT')['title'] ?? null);
        self::assertNull(RevisionHistory::translation(($history->version($page, 'pt-PT', 1) ?? self::fail('no version'))->revision, 'pt-PT'));
    }

    public function testCopesWithSnapshotsWithoutTranslationsOrState(): void
    {
        $fixture = new PageFixture();
        $page = $fixture->editor()->create('en-GB', new PageContent('About'));
        $revision = $fixture->repository()->revisions($page)[0];
        $snapshot = new \ReflectionProperty(PageRevision::class, 'snapshot');
        $snapshot->setValue($revision, ['state' => 'gone', 'translations' => 'none']);

        self::assertNull(RevisionHistory::translation($revision, 'en-GB'));
        $snapshot->setValue($revision, ['translations' => ['en-GB' => ['title' => 'About']]]);
        self::assertNull(new RevisionHistory($fixture->repository())->version($page, 'en-GB', 1)?->state);
    }
}
