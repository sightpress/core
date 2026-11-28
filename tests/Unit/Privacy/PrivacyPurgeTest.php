<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Privacy;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Privacy\Command\PurgeCommand;
use SightPress\Core\Privacy\Command\SitePrivacyCommand;
use SightPress\Core\Privacy\PrivacyPurge;
use SightPress\Core\Privacy\PrivacyPurgeJob;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Module\Console\Command;

#[CoversClass(PrivacyPurge::class)]
#[CoversClass(PrivacyPurgeJob::class)]
#[CoversClass(PurgeCommand::class)]
#[CoversClass(SitePrivacyCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class PrivacyPurgeTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('privacy_purge');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testRunsOnceADayAndAuditsTheCountsOnly(): void
    {
        $services = $this->site->container();
        $connection = Services::get($services, Connection::class);
        $connection->insert('audit_log', ['occurred_at' => '2020-01-01 00:00:00', 'event_action' => 'page.saved', 'entity_type' => 'page', 'summary' => 'Ada Lovelace saved a page.']);
        $connection->insert('invitations', ['email' => 'old@example.org', 'role' => 'editor', 'first_name' => 'Old', 'last_name' => 'Invite', 'created_at' => '2020-01-01 00:00:00', 'expires_at' => '2020-01-08 00:00:00']);
        $now = Services::get($services, ClockInterface::class)->now();
        $job = new PrivacyPurgeJob();

        $job($first = new ScheduleDue($this->site->site(), $services, $now));
        self::assertSame(['Privacy: deleted 2 records older than the site keeps them.'], $first->lines());
        $job($again = new ScheduleDue($this->site->site(), $services, $now));
        self::assertSame([], $again->lines(), 'once a day');

        $audit = $connection->fetchAllAssociative("SELECT summary, details FROM audit_log WHERE event_action = 'privacy.purged'");
        self::assertCount(1, $audit);
        self::assertSame('Deleted personal data older than the site keeps it: 1 invitations, 1 audit entries.', $audit[0]['summary']);
        self::assertStringNotContainsString('old@example.org', Row::text($audit[0]['details']) ?? '');
        self::assertSame(0, Row::int($connection->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_action = 'page.saved'")));

        $next = new ScheduleDue($this->site->site(), $services, $now->modify('+1 day'));
        $job($next);
        self::assertSame([], $next->lines(), 'nothing left to delete');
        self::assertSame('Checked the personal data the site keeps: nothing was older than the site keeps it.', $connection->fetchOne("SELECT summary FROM audit_log WHERE event_action = 'privacy.purged' ORDER BY id DESC"));
    }

    public function testTheCommandRunsItNowForOneSite(): void
    {
        $command = Services::get($this->site->kernel->container(), PurgeCommand::class);
        $output = new RecordingOutput();

        self::assertSame('site:privacy:purge', $command->name());
        self::assertStringContainsString('retention', $command->description());
        self::assertSame('slug', $command->parameters()[0]->name);
        self::assertSame(Command::SUCCESS, $command->run(new ArrayCommandInput(['slug' => 'demo']), $output));
        self::assertContains('Audit entries: 0 deleted.', $output->lines);
        self::assertSame('The personal data of "demo" is within its retention.', end($output->lines));

        self::assertSame(Command::FAILURE, $command->run(new ArrayCommandInput(['slug' => 'nope']), $output = new RecordingOutput()));
        self::assertSame(['There is no site "nope". See the sites with site:list.'], $output->errors);
    }
}
