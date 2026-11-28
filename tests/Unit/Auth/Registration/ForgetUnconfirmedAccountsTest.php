<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Registration\ForgetUnconfirmedAccounts;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\User\Contract\UnverifiedAccounts;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(ForgetUnconfirmedAccounts::class)]
#[UsesNamespace('SightPress\Core')]
final class ForgetUnconfirmedAccountsTest extends TestCase
{
    public function testRemovesTheAccountsNeverConfirmedAWeekAfterTheyWereMade(): void
    {
        $auth = new AuthFixture();
        $registrar = new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock);
        $registrar->startUnverified('old@example.org', 'Old', 'One', null, 'UTC', null, 'hash');
        $registrar->startUnverified('older@example.org', 'Older', 'One', null, 'UTC', null, 'hash');
        $auth->clock->advance(86400);
        $registrar->startUnverified('new@example.org', 'New', 'One', null, 'UTC', null, 'hash');
        $retention = new RetentionPolicy($auth->database->settings(), TenantConfig::fromArray([], '/tmp/project'), new RecordingEvents());
        $services = new ArrayContainer([UnverifiedAccounts::class => $registrar, RetentionPolicy::class => $retention]);

        $early = new ScheduleDue($auth->site, $services, $auth->clock->now()->modify('+6 days'));
        new ForgetUnconfirmedAccounts()($early);
        self::assertSame([], $early->lines());

        $due = new ScheduleDue($auth->site, $services, $auth->clock->now()->modify('+6 days +1 minute'));
        new ForgetUnconfirmedAccounts()($due);
        self::assertSame(['Removed 2 accounts whose email address was never confirmed.'], $due->lines());

        $later = new ScheduleDue($auth->site, $services, $auth->clock->now()->modify('+8 days'));
        new ForgetUnconfirmedAccounts()($later);
        self::assertSame(['Removed 1 account whose email address was never confirmed.'], $later->lines());

        $auth->database->settings()->set('privacy.retention.unverified_days', '1');
        $registrar->startUnverified('soon@example.org', 'Soon', 'One', null, 'UTC', null, 'hash');
        $soon = new ScheduleDue($auth->site, $services, $auth->clock->now()->modify('+1 day +1 minute'));
        new ForgetUnconfirmedAccounts()($soon);
        self::assertSame(['Removed 1 account whose email address was never confirmed.'], $soon->lines(), 'the site\'s retention');
    }
}
