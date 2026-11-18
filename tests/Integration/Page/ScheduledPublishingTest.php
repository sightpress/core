<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Command\RunScheduleCommand;
use SightPress\Core\Site\ScheduleLock;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Scheduled publishing (P-3) through the whole kernel: an editor schedules an approved page in their own time zone
 * from the editor, `schedule:run` publishes it on the first run after its time and never twice, a run that finds
 * another one going stops, and the page list and the audit log say what happened.
 */
#[CoversNothing]
final class ScheduledPublishingTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('scheduled-publishing');
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        $user = new User('bea@example.org', 'Bea', 'Tester', 'America/New_York', $now);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('editor') ?? throw new \LogicException('editor'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testSchedulesAndPublishesAPageOnce(): void
    {
        $container = $this->site->container();
        $page = Services::get($container, PageEditor::class)->create('en-GB', new PageContent('Winter concert'));
        $page->changeState(PageState::Approved, new DateTimeImmutable());
        Services::get($container, PageRepository::class)->flush();
        $id = (int) $page->id();

        $bea = new Browser($this->site->kernel, 'https://example.org');
        $bea->submit('/login', ['email' => 'bea@example.org', 'password' => self::PASSWORD]);
        $local = new DateTimeImmutable('+2 minutes', new \DateTimeZone('America/New_York'));
        $scheduled = $bea->submit('/admin/pages/' . $id, ['op' => 'save', 'publish_at' => $local->format('Y-m-d\TH:i'), 'unpublish_at' => ''], '/admin/pages/' . $id . '/schedule');
        self::assertSame(303, $scheduled->getStatusCode(), (string) $scheduled->getBody());
        $editor = (string) $bea->request('GET', '/admin/pages/' . $id)->getBody();
        self::assertStringContainsString('(America/New_York time)', $editor);
        self::assertStringContainsString('value="' . $local->format('Y-m-d\TH:i') . '"', $editor);
        self::assertStringContainsString('Approved · To be published at', (string) $bea->request('GET', '/admin/pages')->getBody());

        [$code, $status] = $this->runSchedule();
        self::assertSame(0, $code);
        self::assertStringNotContainsString('Winter concert', $status, 'not yet');

        $pages = Services::get($this->site->container(), PageRepository::class);
        $stored = $pages->find($id) ?? self::fail('The page is gone.');
        $stored->schedule(new DateTimeImmutable('-1 minute'), null, $stored->scheduledBy(), new DateTimeImmutable());
        $pages->flush();

        $lock = new ScheduleLock($this->site->project->path() . '/' . RunScheduleCommand::LOCK_FILE);
        self::assertTrue($lock->acquire());
        [$code, $status] = $this->runSchedule();
        self::assertSame(0, $code);
        self::assertSame("Another run is still going; this one stops.\n", $status);
        $lock->release();

        [$code, $status] = $this->runSchedule();
        self::assertSame(0, $code);
        self::assertStringContainsString('demo: Published “Winter concert” on schedule.', $status);
        [$code, $status] = $this->runSchedule();
        self::assertSame(0, $code);
        self::assertStringNotContainsString('Winter concert', $status, 'never twice');

        self::assertSame(PageState::Published, Services::get($this->site->container(), PageRepository::class)->find($id)?->state());
        self::assertSame(200, new Browser($this->site->kernel, 'https://example.org')->request('GET', '/')->getStatusCode());
        $actions = array_map(static fn ($entry): string => $entry->action, Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 50));
        self::assertContains('page.scheduled', $actions);
        self::assertCount(1, array_filter($actions, static fn (string $action): bool => $action === 'page.publish'));
    }

    /**
     * @return array{int, string} the exit code and what it wrote
     */
    private function runSchedule(): array
    {
        $application = $this->site->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'schedule:run'], ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }
}
