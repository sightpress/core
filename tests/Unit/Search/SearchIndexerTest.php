<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use SightPress\Module\Event\MediaChanged;
use SightPress\Module\Event\MediaDeleted;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageSaved;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Core\Page\Contract\SearchablePages;
use SightPress\Module\Event\TranslationPublished;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Search\SearchIndexer;
use SightPress\Module\Event\LocaleRemoved;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Core\Tests\Support\Search\RecordingSearchBackend;
use SightPress\Core\Tests\Support\Search\SearchFixture;
use Stringable;

#[CoversClass(SearchIndexer::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchIndexerTest extends TestCase
{
    private SearchFixture $search;

    private SearchIndex $index;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    private bool $broken = false;

    private SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->search = new SearchFixture(new RecordingSearchBackend(), source: SearchablePages::SOURCE);
        $this->search->provider->add('1', 'About');
        $this->search->provider->add('2', 'Team');
        $this->search->provider->add('2', 'Equipa', 'pt-PT');
        $this->index = $this->search->index();
        $test = $this;
        $pages = new class ($test) implements SearchablePages {
            public function __construct(private readonly SearchIndexerTest $test)
            {
            }

            public function withPagesBelow(int $pageId): array
            {
                $this->test->failIfBroken();

                return $pageId === 1 ? [1, 2] : [$pageId];
            }

            public function usingMedia(int $mediaId): array
            {
                return $mediaId === 7 ? [2, 0] : [];
            }
        };
        $this->logged = new \ArrayObject();
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged->append((is_string($level) ? $level : '?') . ': ' . $message . ' ' . json_encode($context));
            }
        };
        $this->indexer = new SearchIndexer($this->index, $pages, $this->search->site, $logger);
    }

    public function failIfBroken(): void
    {
        if ($this->broken) {
            throw new RuntimeException('database gone');
        }
    }

    public function testPageChangesRefreshThePageAndThePagesBelowIt(): void
    {
        ($this->indexer)(new PageTransitioned(1, PageTransition::Publish->value, PageState::Approved->value, PageState::Published->value, 5));
        self::assertSame(['index page:1:en-GB', 'remove page:1:pt-PT', 'index page:2:en-GB', 'index page:2:pt-PT'], $this->calls());

        ($this->indexer)(new PageMoved(2, null, 1, 0, 3, 5));
        self::assertSame(['index page:2:en-GB', 'index page:2:pt-PT'], $this->calls());

        ($this->indexer)(new PageSaved(2, 'pt-PT', 4, 5));
        self::assertSame(['index page:2:pt-PT'], $this->calls(), 'only the language that was saved');

        ($this->indexer)(new TranslationPublished(2, 'en-GB', false, 4, 5));
        self::assertSame(['index page:2:en-GB'], $this->calls());

        $this->search->provider->drop('2');
        ($this->indexer)(new PagePurged(2, 'Team', [2, 3, 0], 5));
        self::assertSame(['remove page:2:*', 'remove page:3:*'], $this->calls());
    }

    public function testMediaChangesRefreshThePagesThatShowTheFile(): void
    {
        ($this->indexer)(new MediaChanged(7, 5, ['alt']));
        self::assertSame(['index page:2:en-GB', 'index page:2:pt-PT'], $this->calls());

        ($this->indexer)(new MediaDeleted(8, 'old.jpg', 5));
        self::assertSame([], $this->calls(), 'no page shows it');
    }

    public function testSiteWideChangesMarkTheIndexOutOfDate(): void
    {
        $this->index->reindex();
        ($this->indexer)(new SettingsChanged(['site.name'], 5));
        ($this->indexer)(new SiteModuleChanged('acme.events', 'Events', SiteModuleChanged::ENABLED, 5));
        ($this->indexer)(new \stdClass());
        self::assertTrue($this->index->isReady());

        foreach ([
            new SettingsChanged([SiteVisibility::SETTING], 5),
            new SettingsChanged(['site.name', SiteProfile::LOCALES], 5),
            new SettingsChanged([SiteProfile::DEFAULT_LOCALE], null),
            new SiteModuleChanged('search', 'Site search', SiteModuleChanged::DISABLED, 5),
            new SiteModuleChanged('search', 'Site search', SiteModuleChanged::ENABLED, 5),
        ] as $event) {
            $this->index->reindex();
            ($this->indexer)($event);
            self::assertFalse($this->index->isReady(), $event::class);
        }

        $this->calls();
        ($this->indexer)(new LocaleRemoved('pt-PT', 5));
        self::assertSame(['clear pt-PT'], $this->calls());
    }

    public function testAFailureIsLoggedAndMarksTheIndexOutOfDate(): void
    {
        $this->index->reindex();
        $this->broken = true;

        ($this->indexer)(new PageMoved(1, null, null, 0, 2, 5));

        self::assertFalse($this->index->isReady());
        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('error: Site search could not be brought up to date; it will be rebuilt by schedule:run or site:search:reindex. {"site":"demo","event":"SightPress\\\\Module\\\\Event\\\\PageMoved","error":"database gone"}', (string) $this->logged[0]);
    }

    /**
     * The back-end calls since the last time.
     *
     * @return list<string>
     */
    private function calls(): array
    {
        $backend = $this->search->replacement;
        self::assertNotNull($backend);
        $calls = $backend->calls;
        $backend->calls = [];

        return $calls;
    }
}
