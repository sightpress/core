<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Privacy;

use DateTimeImmutable;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Privacy\AssistantUsageData;
use SightPress\Core\Audit\Privacy\AuditLogData;
use SightPress\Core\Auth\Privacy\CodeData;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Privacy\BatchedDelete;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\People\Privacy\InvitationData;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Sms\Privacy\SmsLogData;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Module\Event\SettingsChanged;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RetentionPolicy::class)]
#[CoversClass(BatchedDelete::class)]
#[CoversClass(AuditLogData::class)]
#[CoversClass(InvitationData::class)]
#[CoversClass(CodeData::class)]
#[CoversClass(VerificationCodeStore::class)]
#[CoversClass(SmsLogData::class)]
#[CoversClass(AssistantUsageData::class)]
#[UsesNamespace('SightPress\Core')]
final class RetentionTest extends TestCase
{
    public function testTheSiteChoosesWithinTheTenantsLimits(): void
    {
        $settings = new ArraySiteSettings(['privacy.retention.sms_days' => '5', 'privacy.retention.code_days' => 'x']);
        $events = new RecordingEvents();
        $policy = new RetentionPolicy($settings, TenantConfig::fromArray([], '/tmp/project'), $events);

        self::assertSame(['audit_months', 'unverified_days', 'invitation_days', 'code_days', 'sms_days', 'assistant_months'], $policy->keys());
        self::assertSame(90, $policy->value('sms_days'), 'a stored value outside the limits falls back to the default');
        self::assertSame(1, $policy->value('code_days'));
        $now = new DateTimeImmutable('2026-11-28 12:00:00 UTC');
        self::assertSame('2024-11-28 12:00:00', $policy->cutOff('audit_months', $now)->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-30 12:00:00', $policy->cutOff('sms_days', $now)->format('Y-m-d H:i:s'));

        self::assertSame(['privacy.retention.sms_days'], $policy->change(['sms_days' => 30, 'audit_months' => 24], 7));
        self::assertSame(30, $policy->value('sms_days'));
        self::assertSame([], $policy->change(['sms_days' => 30], 7));
        self::assertCount(1, $events->events);
        self::assertInstanceOf(SettingsChanged::class, $events->events[0]);
        $this->expectExceptionMessage('Retention "audit_months" must be between 6 and 84.');
        $policy->change(['audit_months' => 5], 7);
    }

    public function testEachKindIsKeptUntilItsBoundaryAndNotASecondLonger(): void
    {
        $connection = SiteTables::connection();
        $now = new DateTimeImmutable('2026-11-28 12:00:00 UTC');
        $policy = new RetentionPolicy(new ArraySiteSettings(), TenantConfig::fromArray([], '/tmp/project'), new RecordingEvents());
        $at = static fn (string $key, int $seconds): string => $policy->cutOff($key, $now)->modify(sprintf('%+d seconds', $seconds))->format('Y-m-d H:i:s');
        foreach ([[1, -1], [2, 1]] as [$id, $offset]) {
            $connection->insert('audit_log', ['id' => $id, 'occurred_at' => $at('audit_months', $offset), 'event_action' => 'a', 'entity_type' => 'x', 'summary' => 's']);
            $connection->insert('invitations', ['id' => $id, 'email' => $id . '@example.org', 'role' => 'editor', 'first_name' => 'A', 'last_name' => 'B', 'created_at' => '2026-01-01 00:00:00', 'expires_at' => $at('invitation_days', $offset)]);
            $connection->insert('verification_code', ['id' => $id, 'purpose' => 'email_verify', 'channel' => 'email', 'target_hash' => 'h' . $id, 'code_hash' => 'c', 'attempts' => 0, 'created_at' => '2026-01-01 00:00:00', 'expires_at' => $at('code_days', $offset)]);
            $connection->insert('sms_message', ['id' => $id, 'created_at' => $at('sms_days', $offset), 'month' => '2026-08', 'purpose' => 'security_code', 'to_masked' => 'm', 'to_hash' => 'h', 'provider' => 'twilio', 'parts' => 1, 'status' => 'sent', 'body' => 'b', 'updated_at' => '2026-08-01 00:00:00']);
            $connection->insert('assistant_usage', ['id' => $id, 'used_at' => $at('assistant_months', $offset), 'month' => '2025-10', 'user_id' => 1, 'task' => 't', 'provider' => 'p', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'outcome' => 'ok']);
        }
        $connection->insert('invitations', ['id' => 3, 'email' => '3@example.org', 'role' => 'editor', 'first_name' => 'A', 'last_name' => 'B', 'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00', 'accepted_at' => $at('invitation_days', -1)]);
        $hashes = new SmsNumberHash(TenantConfig::fromArray([], '/tmp/project'), SiteFixture::site());
        $providers = [
            'audit_log' => new AuditLogData($connection),
            'invitations' => new InvitationData($connection),
            'verification_code' => new CodeData(new VerificationCodeStore($connection, new FixedClock($now), new RateLimiters(new InMemoryStorage()), 'secret')),
            'sms_message' => new SmsLogData($connection, $hashes),
            'assistant_usage' => new AssistantUsageData($connection),
        ];

        foreach ($providers as $table => $provider) {
            $counts = $provider->purgeExpired($policy, $now);
            self::assertSame($table === 'invitations' ? 2 : 1, array_sum($counts), $table);
            self::assertSame([2], array_map(Row::int(...), $connection->fetchFirstColumn('SELECT id FROM ' . $table)), $table . ': one second inside the retention is kept');
            self::assertSame(0, array_sum($provider->purgeExpired($policy, $now)), $table . ': running again changes nothing');
        }
    }

    public function testDeletesInBatchesAndAnInterruptedRunIsFinishedByTheNext(): void
    {
        $connection = SiteTables::connection();
        for ($id = 1; $id <= 7; ++$id) {
            $connection->insert('audit_log', ['id' => $id, 'occurred_at' => '2020-01-01 00:00:00', 'event_action' => 'a', 'entity_type' => 'x', 'summary' => 's']);
        }
        $old = static fn (QueryBuilder $query): QueryBuilder => $query->where("occurred_at < '2021-01-01'");

        self::assertSame(7, BatchedDelete::run($connection, 'audit_log', 'id', $old, 3));
        self::assertSame(0, BatchedDelete::run($connection, 'audit_log', 'id', $old, 3));
        $connection->insert('audit_log', ['id' => 8, 'occurred_at' => '2020-01-01 00:00:00', 'event_action' => 'a', 'entity_type' => 'x', 'summary' => 's']);
        $connection->insert('session', ['id_hash' => 'a', 'data' => '{}', 'last_seen_at' => '2020-01-01 00:00:00', 'expires_at' => '2020-01-01 00:00:00']);
        self::assertSame(1, BatchedDelete::run($connection, 'session', 'id_hash', static fn (QueryBuilder $query): QueryBuilder => $query->where("expires_at < '2021-01-01'")));
        self::assertSame(1, BatchedDelete::run($connection, 'audit_log', 'id', $old));
    }
}
