<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Search\Command\ReindexSearchCommand;
use SightPress\Core\Search\Command\SearchCommand;
use SightPress\Core\Search\Command\SearchStatusCommand;
use SightPress\Core\Search\Command\SiteSearchCommand;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;

#[CoversClass(SiteSearchCommand::class)]
#[CoversClass(ReindexSearchCommand::class)]
#[CoversClass(SearchStatusCommand::class)]
#[CoversClass(SearchCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchCommandsTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('search_commands', locales: ['en-GB', 'pt-PT']);
        $editor = Services::get($this->site->container(), PageEditor::class);
        $now = new DateTimeImmutable();
        $editor->create('en-GB', new PageContent('Opening hours', 'hours', 'When the library is open.'))->changeState(PageState::Published, $now);
        $editor->create('en-GB', new PageContent('Library events', 'events'))->changeState(PageState::Published, $now);
        $members = $editor->create('en-GB', new PageContent('Library committee', 'committee'));
        $members->changeState(PageState::Published, $now);
        $members->setPublic(false, $now);
        Services::get($this->site->container(), PageRepository::class)->flush();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testReindexesShowsTheStatusAndSearches(): void
    {
        $status = $this->command(SearchStatusCommand::class);
        self::assertSame(['site:search:status', 1], [$status->name(), count($status->parameters())]);
        self::assertSame(
            ['en-GB: 0 documents.', 'pt-PT: 0 documents.', 'The index is out of date: schedule:run builds it again within a minute, or run site:search:reindex demo now.'],
            $this->execute($status, ['slug' => 'demo'])[1]->lines,
        );

        $reindex = $this->command(ReindexSearchCommand::class);
        self::assertSame(['site:search:reindex', 2], [$reindex->name(), count($reindex->parameters())]);
        self::assertSame(['en-GB: 3 documents indexed.', 'pt-PT: 0 documents indexed.'], $this->execute($reindex, ['slug' => 'demo'])[1]->lines);
        self::assertSame(['pt-PT: 0 documents indexed.'], $this->execute($reindex, ['slug' => 'demo'], ['locale' => 'pt-PT'])[1]->lines);

        $lines = $this->execute($status, ['slug' => 'demo'])[1]->lines;
        self::assertMatchesRegularExpression('/^en-GB: 3 documents, last indexed \d{4}-\d\d-\d\d \d\d:\d\d UTC\.$/', $lines[0]);
        self::assertSame(['pt-PT: 0 documents.'], array_slice($lines, 1));

        $search = $this->command(SearchCommand::class);
        self::assertSame(['site:search', 4], [$search->name(), count($search->parameters())]);
        foreach ([$status, $reindex, $search] as $command) {
            self::assertNotSame('', $command->description());
        }
        self::assertSame(
            ['1 result for "library events" in en-GB:', '1. Library events — /events (score %s)'],
            self::scores($this->execute($search, ['slug' => 'demo', 'text' => ' library   events '])[1]->lines),
        );
        $lines = self::scores($this->execute($search, ['slug' => 'demo', 'text' => 'library'], flags: ['members'])[1]->lines);
        self::assertSame(['3 results for "library" in en-GB:', '3. Opening hours — / (score %s)'], [$lines[0], $lines[3] ?? null]);
        // The two titles score the same; the newer page comes first, and they may have been made in different seconds.
        self::assertEqualsCanonicalizing(['Library events — /events (score %s)', 'Library committee — /committee (score %s)'], array_map(static fn (string $line): string => substr($line, 3), [$lines[1] ?? '', $lines[2] ?? '']));
        self::assertSame(
            ['2 results for "library concerts" in en-GB (no page has every word, so these have some of them):', '1. Library events — /events (score %s)', '2. Opening hours — / (score %s)'],
            self::scores($this->execute($search, ['slug' => 'demo', 'text' => 'library concerts'])[1]->lines),
        );
        self::assertSame(['No results for "biblioteca" in pt-PT.'], $this->execute($search, ['slug' => 'demo', 'text' => 'biblioteca'], ['locale' => 'pt-PT'])[1]->lines);
        [$code, $output] = $this->execute($search, ['slug' => 'demo', 'text' => '   ']);
        self::assertSame([1, ['Type something to search for.']], [$code, $output->errors]);
    }

    public function testRefusesUnknownSitesLanguagesAndSitesWithSearchOff(): void
    {
        $reindex = $this->command(ReindexSearchCommand::class);

        self::assertSame([1, ['There is no site "shop". See the sites with site:list.']], self::errors($this->execute($reindex, ['slug' => 'shop'])));
        self::assertSame([1, ['The site "demo" isn\'t written in "fr". Its languages are: en-GB, pt-PT.']], self::errors($this->execute($reindex, ['slug' => 'demo'], ['locale' => 'fr'])));

        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'search', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        self::assertSame([1, ['Search is off for the site "demo". Turn it on with: site:module:enable demo search']], self::errors($this->execute($this->command(ReindexSearchCommand::class), ['slug' => 'demo'])));
    }

    /**
     * @template T of SiteSearchCommand
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class): SiteSearchCommand
    {
        $tenant = $this->site->kernel->container();

        return new $class(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $options
     * @param list<string>          $flags
     *
     * @return array{int, RecordingOutput}
     */
    private function execute(SiteSearchCommand $command, array $arguments, array $options = [], array $flags = []): array
    {
        $output = new RecordingOutput();

        return [$command->run(new ArrayCommandInput($arguments, $options, $flags), $output), $output];
    }

    /**
     * @param array{int, RecordingOutput} $result
     *
     * @return array{int, list<string>}
     */
    private static function errors(array $result): array
    {
        return [$result[0], $result[1]->errors];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function scores(array $lines): array
    {
        return array_map(static fn (string $line): string => (string) preg_replace('/\(score \d+\.\d\d\)$/', '(score %s)', $line), $lines);
    }
}
