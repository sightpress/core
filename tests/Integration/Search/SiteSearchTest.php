<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Search;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Search\DatabaseSearchBackend;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Search\SiteSearch;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\Modules\ExhibitionsModule;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Search\SearchHit;

/**
 * SR-1 acceptance through the kernel: the index follows the workflow (published pages are found, anything taken off
 * the site is gone, moved pages have their new address), drafts, pages left out of search and other languages are
 * never returned, members-only pages only for people signed in, a module's own documents are searchable, and a
 * reindex gives the same index every time.
 */
#[CoversNothing]
final class SiteSearchTest extends TestCase
{
    private KernelSite $site;

    private PageEditor $editor;

    private PageWorkflow $workflow;

    private Actor $admin;

    protected function setUp(): void
    {
        $this->site = new KernelSite('site_search', locales: ['en-GB', 'pt-PT'], extraModules: [new ModuleCandidate(new ExhibitionsModule(), 'acme/exhibitions', '*')]);
        $container = $this->site->container();
        $this->editor = Services::get($container, PageEditor::class);
        $this->workflow = Services::get($container, PageWorkflow::class);
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash('$argon2id$v=19$m=8,t=1,p=1$c2FsdHNhbHQ$aGFzaA', new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('admin') ?? self::fail('admin'));
        $this->admin = new Actor((int) $user->id(), $roles->permissionNamesOf((int) $user->id()));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheIndexFollowsTheWorkflow(): void
    {
        $this->editor->create('en-GB', new PageContent('Home', 'home', 'Welcome to the library.'));
        $page = $this->editor->create('en-GB', new PageContent('River walks', 'river-walks', 'Walks along the river every Sunday.'));
        self::assertSame([], $this->search('river'), 'a draft');

        $this->publish($page);
        self::assertSame(['/river-walks'], $this->search('river'));
        self::assertSame(['/river-walks'], $this->search('sunday walk'));

        $this->workflow->apply($page, PageTransition::Unpublish, $this->admin);
        self::assertSame([], $this->search('river'), 'unpublished');

        $this->publish($page);
        $visit = $this->editor->create('en-GB', new PageContent('Visit us', 'visit', 'How to find us.'));
        $this->publish($visit);
        $this->editor->move($page, $visit, 0, $this->admin->userId);
        self::assertSame(['/visit/river-walks'], $this->search('river'), 'moved');
        $this->editor->save($visit, 'en-GB', new PageContent('Visiting', 'visiting', 'How to find us.'), $this->admin->userId);
        self::assertSame(['/visiting/river-walks'], $this->search('river'), 'the page above changed its address');
        self::assertSame(['Visiting'], $this->hits('river')[0]->document->breadcrumb);

        $this->workflow->apply($page, PageTransition::Archive, $this->admin);
        self::assertSame([], $this->search('river'), 'archived');
        $this->workflow->apply($page, PageTransition::Restore, $this->admin);
        $this->publish($page);
        self::assertSame(['/visiting/river-walks'], $this->search('river'));

        $this->workflow->apply($page, PageTransition::Trash, $this->admin);
        self::assertSame([], $this->search('river'), 'in the trash');
        $this->workflow->apply($page, PageTransition::Untrash, $this->admin);
        $this->publish($page);
        $this->workflow->apply($page, PageTransition::Trash, $this->admin);
        $id = (int) $page->id();
        $this->editor->purge($page, $this->admin->userId);
        self::assertSame(0, $this->documents('page', (string) $id), 'deleted for good');
    }

    public function testDraftsPagesLeftOutOtherLanguagesAndMembersPages(): void
    {
        $this->editor->create('en-GB', new PageContent('Home', 'home', 'Welcome to the library.'));
        $page = $this->editor->create('en-GB', new PageContent('Harbour tours', 'tours', 'Boat tours of the harbour.'));
        $this->publish($page);
        $this->editor->save($page, 'pt-PT', new PageContent('Passeios no porto', 'passeios', 'Passeios de barco no porto.'), $this->admin->userId);
        self::assertSame([], $this->search('porto', 'pt-PT'), 'a translation that is a draft of its own');
        $this->editor->publishTranslation($page, 'pt-PT', true, $this->admin->userId);
        self::assertSame(['/pt-pt/passeios'], $this->search('porto', 'pt-PT'));
        self::assertSame([], $this->search('porto'), 'one language at a time');
        self::assertSame([], $this->search('harbour', 'pt-PT'));

        $this->editor->save($page, 'en-GB', new PageContent('Harbour tours', 'tours', 'Boat tours of the harbour.', excludeFromSiteSearch: true), $this->admin->userId);
        self::assertSame([], $this->search('harbour'), 'left out of site search');
        self::assertSame(['/pt-pt/passeios'], $this->search('porto', 'pt-PT'), 'only in that language');
        $this->editor->save($page, 'en-GB', new PageContent('Harbour tours', 'tours', 'Boat tours of the harbour.'), $this->admin->userId);
        self::assertSame(['/tours'], $this->search('harbour'));

        $page->setPublic(false, new DateTimeImmutable());
        $this->editor->save($page, 'en-GB', new PageContent('Harbour tours', 'tours', 'Boat tours of the harbour.'), $this->admin->userId);
        self::assertSame([], $this->search('harbour'), 'members only');
        self::assertSame(['/tours'], $this->search('harbour', signedIn: true));
    }

    public function testAModulesOwnDocumentsAreIndexedAndAReindexIsIdempotent(): void
    {
        $home = $this->editor->create('en-GB', new PageContent('Home', 'home', 'Photographs of the harbour.'));
        $home->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();
        $index = Services::get($this->site->container(), SearchIndex::class);
        self::assertSame([], $this->search('harbour'), 'published without the workflow: not indexed yet');
        self::assertFalse($index->isReady());

        self::assertSame(['en-GB' => 3, 'pt-PT' => 0], $index->reindex());
        $rows = [$this->documents(), $this->rows(DatabaseSearchBackend::POSTINGS), $this->rows(DatabaseSearchBackend::WORDS)];
        self::assertSame(['en-GB' => 3, 'pt-PT' => 0], $index->reindex());
        self::assertSame($rows, [$this->documents(), $this->rows(DatabaseSearchBackend::POSTINGS), $this->rows(DatabaseSearchBackend::WORDS)], 'the same index');
        self::assertTrue($index->isReady());

        self::assertSame(['/exhibitions/maps', '/'], $this->search('harbour'));
        self::assertEqualsCanonicalizing(['/exhibitions/preview', '/exhibitions/maps', '/'], $this->search('harbour', signedIn: true));
        self::assertSame(['/exhibitions/maps'], $this->search('charts 1850'));
    }

    public function testTheSearchModuleWritesNoRawSql(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src/Search', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            ++$checked;
            $code = (string) file_get_contents($file->getPathname());
            foreach (token_get_all($code) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    self::assertDoesNotMatchRegularExpression('/^[\'"]\s*(SELECT\b.*\bFROM\b|INSERT\s+INTO|UPDATE\s+\S+\s+SET|DELETE\s+FROM|CREATE\s+(TABLE|INDEX|UNIQUE)|ALTER\s+TABLE|DROP\s+(TABLE|INDEX)|PRAGMA|WITH\s+\w+\s+AS)/is', $token[1], $file->getFilename());
                }
            }
            self::assertDoesNotMatchRegularExpression('/connection->(executeQuery|executeStatement|executeUpdate|prepare|exec|query|fetch\w*)\(/i', $code, $file->getFilename() . ': SQL passed to the connection');
        }
        self::assertGreaterThan(10, $checked);
    }

    private function publish(Page $page): void
    {
        if ($page->state() === PageState::Draft) {
            $this->workflow->apply($page, PageTransition::Submit, $this->admin);
        }
        if ($page->state() === PageState::InReview) {
            $this->workflow->apply($page, PageTransition::Approve, $this->admin);
        }
        $this->workflow->apply($page, PageTransition::Publish, $this->admin);
    }

    /**
     * @param non-empty-string $locale
     *
     * @return list<SearchHit>
     */
    private function hits(string $text, string $locale = 'en-GB', bool $signedIn = false): array
    {
        $query = SiteSearch::query($text, $locale, $signedIn) ?? self::fail('no query');

        return Services::get($this->site->container(), SiteSearch::class)->search($query)->hits;
    }

    /**
     * @param non-empty-string $locale
     *
     * @return list<string> the addresses found, best first
     */
    private function search(string $text, string $locale = 'en-GB', bool $signedIn = false): array
    {
        return array_map(static fn (SearchHit $hit): string => $hit->document->path, $this->hits($text, $locale, $signedIn));
    }

    private function documents(?string $source = null, ?string $ref = null): int
    {
        $query = $this->connection()->createQueryBuilder()->select('COUNT(*)')->from(DatabaseSearchBackend::DOCUMENTS);
        if ($source !== null) {
            $query->where('source = :source')->andWhere('ref = :ref')->setParameter('source', $source)->setParameter('ref', $ref);
        }

        return self::number($query->executeQuery()->fetchOne());
    }

    private static function number(mixed $count): int
    {
        return is_numeric($count) ? (int) $count : -1;
    }

    private function rows(string $table): int
    {
        return self::number($this->connection()->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne());
    }

    private function connection(): Connection
    {
        return Services::get($this->site->container(), Connection::class);
    }
}
