<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Privacy;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Privacy\PrivacyPurge;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * Retention on the site's real database engine (GD-2, DB matrix): every kind is kept until one second past its
 * boundary and then deleted, in batches; running again changes nothing.
 */
#[CoversNothing]
final class RetentionPurgeTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('retention_purge', tenant: ['sms' => ['provider' => 'twilio', 'account' => 'AC' . str_repeat('0', 32), 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'key']);
        foreach (['sms', 'assistant'] as $module) {
            Services::get($this->site->kernel->container(), SiteModules::class)->turnOn($this->site->site(), $module, null);
        }
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testEachKindIsDeletedOneSecondAfterItsBoundary(): void
    {
        $services = $this->site->container();
        $connection = Services::get($services, Connection::class);
        $retention = Services::get($services, RetentionPolicy::class);
        $now = new DateTimeImmutable('2026-11-28 12:00:00 UTC');
        $at = static fn (string $key, int $seconds): string => $retention->cutOff($key, $now)->modify(sprintf('%+d seconds', $seconds))->format('Y-m-d H:i:s');
        // 1 100 old audit entries, more than two batches, and one just inside.
        for ($i = 0; $i < 1100; ++$i) {
            $connection->insert('audit_log', ['occurred_at' => $at('audit_months', -1 - $i), 'event_action' => 'page.saved', 'entity_type' => 'page', 'summary' => 'old']);
        }
        $connection->insert('audit_log', ['occurred_at' => $at('audit_months', 1), 'event_action' => 'page.saved', 'entity_type' => 'page', 'summary' => 'kept']);
        foreach ([-1 => 'gone', 1 => 'kept'] as $offset => $name) {
            $connection->insert('invitations', ['email' => $name . '@example.org', 'role' => 'editor', 'first_name' => $name, 'last_name' => 'X', 'created_at' => '2026-01-01 00:00:00', 'expires_at' => $at('invitation_days', $offset)]);
            $connection->insert('verification_code', ['purpose' => 'email_verify', 'channel' => 'email', 'target_hash' => $name, 'code_hash' => 'c', 'attempts' => 0, 'created_at' => '2026-01-01 00:00:00', 'expires_at' => $at('code_days', $offset)]);
            $connection->insert('sms_message', ['created_at' => $at('sms_days', $offset), 'month' => '2026-08', 'purpose' => 'security_code', 'to_masked' => 'm', 'to_hash' => $name, 'provider' => 'twilio', 'parts' => 1, 'status' => 'sent', 'body' => $name, 'updated_at' => '2026-08-01 00:00:00']);
            $connection->insert('assistant_usage', ['used_at' => $at('assistant_months', $offset), 'month' => '2025-10', 'user_id' => 1, 'task' => $name, 'provider' => 'p', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'outcome' => 'ok']);
        }
        $connection->insert('sms_message', ['created_at' => '2020-01-01 00:00:00', 'month' => '2020-01', 'purpose' => 'notification', 'to_masked' => 'm', 'to_hash' => 'waiting', 'provider' => 'twilio', 'parts' => 1, 'status' => 'queued', 'body' => 'waiting', 'updated_at' => '2020-01-01 00:00:00']);
        $purge = Services::get($services, PrivacyPurge::class);

        $counts = $purge->run($now);

        self::assertEqualsCanonicalizing(['invitations' => 1, 'codes' => 1, 'audit entries' => 1100, 'text messages' => 1, 'assistant use records' => 1], array_filter($counts));
        self::assertSame(['kept'], $connection->fetchFirstColumn("SELECT summary FROM audit_log WHERE event_action = 'page.saved'"));
        self::assertSame(['kept@example.org'], $connection->fetchFirstColumn('SELECT email FROM invitations'));
        self::assertSame(['kept'], $connection->fetchFirstColumn('SELECT target_hash FROM verification_code'));
        self::assertEqualsCanonicalizing(['kept', 'waiting'], $connection->fetchFirstColumn('SELECT body FROM sms_message'));
        self::assertSame(['kept'], $connection->fetchFirstColumn('SELECT task FROM assistant_usage'));
        self::assertSame(0, array_sum($purge->run($now)), 'running again changes nothing');
    }
}
