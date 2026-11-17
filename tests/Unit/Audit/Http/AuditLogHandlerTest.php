<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Audit\AuditEntry;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\Http\AuditLogHandler;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(AuditLogHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AuditLogHandlerTest extends TestCase
{
    private AdminFixture $fixture;

    private AuditLog $log;

    private User $admin;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->log = new AuditLog($this->fixture->auth->database->connection);
        $this->admin = $this->fixture->auth->user('ada@example.org', 'admin');
    }

    public function testOnlyPeopleWhoMayViewTheLogSeeIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->get('/admin/audit', $this->fixture->auth->user('bea@example.org', 'editor'));
    }

    public function testSaysWhenNothingWasRecorded(): void
    {
        $html = (string) $this->get('/admin/audit')->getBody();

        self::assertStringContainsString('<p>Nothing has been recorded yet.</p>', $html);
        self::assertStringContainsString('Download the whole log as a CSV file', $html);
    }

    public function testShowsTheEntriesNewestFirstAPageAtATimeWithFilters(): void
    {
        $id = (int) $this->admin->id();
        for ($i = 0; $i < 51; ++$i) {
            $this->log->append(new AuditEntry(new DateTimeImmutable('2026-11-15 10:00:00 UTC'), $id, 'page.saved', 'page', '1', sprintf('Saved “About” (revision %d).', $i + 1)));
        }
        $this->log->append(new AuditEntry(new DateTimeImmutable('2026-11-16 10:00:00 UTC'), null, 'settings.changed', 'settings', null, 'Changed the site settings: Site name.'));
        $this->log->append(new AuditEntry(new DateTimeImmutable('2026-11-16 11:00:00 UTC'), 999, 'media.deleted', 'media', '3', 'Deleted “old.pdf”.'));

        $html = (string) $this->get('/admin/audit')->getBody();
        self::assertStringContainsString('<title>Audit log (page 1 of 2) - Admin - Demo</title>', $html);
        self::assertStringContainsString('<caption id="audit-caption">53 entries, newest first, page 1 of 2</caption>', $html);
        self::assertMatchesRegularExpression('#<td>Account 999</td>\s*<td>Deleted “old.pdf”.</td>\s*<td>Media</td>#', $html);
        self::assertMatchesRegularExpression('#<td>System</td>\s*<td>Changed the site settings: Site name.</td>#', $html);
        self::assertStringContainsString('<a href="/admin/audit?page=2" rel="next">Older entries</a>', $html);
        self::assertStringContainsString('<option value="' . $id . '">Ada</option>', $html);

        $second = (string) $this->get('/admin/audit?page=9')->getBody();
        self::assertStringContainsString('<a href="/admin/audit?page=1" rel="prev">Newer entries</a>', $second, 'a page past the end shows the last one');

        $filtered = (string) $this->get('/admin/audit?who=' . $id . '&type=page&from=2026-11-15&to=2026-11-15')->getBody();
        self::assertStringContainsString('51 entries, newest first, page 1 of 2', $filtered);
        self::assertStringContainsString('page=2" rel="next"', $filtered);
        self::assertStringContainsString('who=' . $id . '&amp;type=page&amp;from=2026-11-15&amp;to=2026-11-15&amp;page=2', $filtered);
        self::assertStringContainsString('<option value="page" selected>Pages</option>', $filtered);
        self::assertStringContainsString('Download these entries as a CSV file', $filtered);

        $none = (string) $this->get('/admin/audit?type=menu&from=17-11-2026')->getBody();
        self::assertStringContainsString('<p>No entries match.</p>', $none);
        self::assertStringContainsString('A date was left out because it isn’t a day such as 2026-11-17.', $none);
        self::assertStringContainsString('1 entry', (string) $this->get('/admin/audit?type=settings&from=2026-11-16&to=2026-11-16')->getBody());
    }

    public function testExportsTheEntriesAsCsvThatSpreadsheetsWontRun(): void
    {
        $this->log->append(new AuditEntry(new DateTimeImmutable('2026-11-16 10:00:00 Europe/Lisbon'), (int) $this->admin->id(), 'page.saved', 'page', '1', '=HYPERLINK("https://evil.example")'));
        $this->log->append(new AuditEntry(new DateTimeImmutable('2026-11-16 11:00:00 UTC'), null, 'settings.changed', 'settings', null, 'Changed, "quoted".'));

        $response = $this->get('/admin/audit.csv');

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="audit-log-demo.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(
            "\"Time (UTC)\",Who,Action,Kind,Id,\"What happened\"\n"
            . "2026-11-16T11:00:00Z,System,settings.changed,settings,,\"Changed, \"\"quoted\"\".\"\n"
            . "2026-11-16T10:00:00Z,Ada,page.saved,page,1,\"'=HYPERLINK(\"\"https://evil.example\"\")\"\n",
            (string) $response->getBody(),
        );
    }

    private function get(string $path, ?User $user = null): ResponseInterface
    {
        $handler = new AuditLogHandler($this->fixture->shell(), $this->log, $this->fixture->auth->users, $this->fixture->auth->http, $this->fixture->auth->site);

        return $handler->handle($this->fixture->request($user ?? $this->admin, 'GET', $path));
    }
}
