<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Feature\Privacy\ModuleChangeData;
use SightPress\Core\Page\Privacy\PageAuthorData;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Module\Privacy\UserRef;

#[CoversClass(PageAuthorData::class)]
#[CoversClass(ModuleChangeData::class)]
#[UsesNamespace('SightPress\Core')]
final class AuthorDataTest extends TestCase
{
    public function testExportsThePagesThePersonWroteAndKeepsThemOnTheTombstone(): void
    {
        $connection = SiteTables::connection();
        foreach ([[1, 7, null], [2, 8, 7], [3, 8, null]] as [$id, $author, $scheduler]) {
            $connection->insert('pages', ['id' => $id, 'sort_order' => $id, 'tree_path' => '/' . $id . '/', 'depth' => 0, 'author_id' => $author, 'scheduled_by' => $scheduler, 'state' => 'draft', 'is_public' => 1, 'layout' => 'default', 'created_at' => '2026-11-0' . $id . ' 10:00:00', 'updated_at' => '2026-11-0' . $id . ' 10:00:00']);
            $connection->insert('page_translations', ['page_id' => $id, 'locale' => 'en-GB', 'title' => 'Page ' . $id, 'slug' => 'p' . $id, 'url_path' => '/p' . $id, 'blocks' => '[]', 'updated_at' => '2026-11-01 10:00:00']);
        }
        foreach ([[1, 1, 7], [2, 1, 7], [3, 3, 8]] as [$id, $page, $by]) {
            $connection->insert('page_revisions', ['id' => $id, 'page_id' => $page, 'revision' => $id, 'saved_by' => $by, 'snapshot' => '{}', 'created_at' => '2026-11-1' . $id . ' 10:00:00']);
        }
        $data = new PageAuthorData($connection);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame('page.authors', $data->describe()->key);
        self::assertSame([
            ['page_id' => 1, 'title' => 'Page 1', 'you_wrote_it' => true, 'you_scheduled_it' => false, 'created_at' => '2026-11-01T10:00:00Z'],
            ['page_id' => 2, 'title' => 'Page 2', 'you_wrote_it' => false, 'you_scheduled_it' => true, 'created_at' => '2026-11-02T10:00:00Z'],
            ['page_id' => 1, 'versions_you_saved' => 2, 'last_saved_at' => '2026-11-12T10:00:00Z'],
        ], iterator_to_array($data->export($ada), false));
        self::assertSame(4, $data->erase($ada)->pseudonymised);
        self::assertSame(7, Row::int($connection->fetchOne('SELECT author_id FROM pages WHERE id = 1')));
    }

    public function testExportsTheFeaturesThePersonTurnedOnOrOff(): void
    {
        $connection = SiteTables::connection();
        $connection->insert('site_module', ['module_id' => 'search', 'enabled' => 1, 'changed_at' => '2026-11-01 10:00:00', 'changed_by' => 7]);
        $connection->insert('site_module', ['module_id' => 'analytics', 'enabled' => 0, 'changed_at' => '2026-11-02 10:00:00', 'changed_by' => 8]);
        $data = new ModuleChangeData($connection);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame([['feature' => 'search', 'on' => true, 'changed_at' => '2026-11-01T10:00:00Z']], iterator_to_array($data->export($ada), false));
        self::assertSame(1, $data->erase($ada)->pseudonymised);
        self::assertSame('feature.changes', $data->describe()->key);
    }
}
