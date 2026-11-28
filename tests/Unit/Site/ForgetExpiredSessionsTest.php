<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\ExpiringSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Site\ForgetExpiredSessions;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(ForgetExpiredSessions::class)]
#[UsesClass(ScheduleDue::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBox::class)]
final class ForgetExpiredSessionsTest extends TestCase
{
    public function testRemovesTheSessionsThatEndedAndSaysHowMany(): void
    {
        $now = new DateTimeImmutable('2026-11-27 12:00:00');
        foreach ([2 => ['Removed 2 sessions that ended.'], 1 => ['Removed 1 session that ended.'], 0 => []] as $removed => $lines) {
            $store = $this->createMock(ExpiringSessionStore::class);
            $store->expects(self::once())->method('forgetExpired')->with($now)->willReturn($removed);
            $event = new ScheduleDue(SiteFixture::site(), new ArrayContainer([SessionStore::class => $store]), $now);

            new ForgetExpiredSessions()($event);

            self::assertSame($lines, $event->lines());
        }
    }

    public function testLeavesStoresThatEndSessionsThemselves(): void
    {
        $redis = new ScheduleDue(SiteFixture::site(), new ArrayContainer([SessionStore::class => self::createStub(SessionStore::class)]), new DateTimeImmutable());
        new ForgetExpiredSessions()($redis);
        $none = new ScheduleDue(SiteFixture::site(), new ArrayContainer(), new DateTimeImmutable());
        new ForgetExpiredSessions()($none);

        self::assertSame([], [...$redis->lines(), ...$none->lines()]);
    }
}
