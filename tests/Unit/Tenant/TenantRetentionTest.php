<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantRetention;

#[CoversClass(TenantRetention::class)]
#[UsesNamespace('SightPress\Core')]
final class TenantRetentionTest extends TestCase
{
    public function testDefaultsAndNarrowerLimitsFromTheTenant(): void
    {
        self::assertSame(TenantRetention::LIMITS, TenantConfig::fromArray([], '/tmp/project')->retention->limits);
        $retention = TenantConfig::fromArray(['privacy' => ['retention' => ['audit_months' => ['default' => 36, 'min' => 12], 'sms_days' => 30]]], '/tmp/project')->retention;

        self::assertSame(['default' => 36, 'min' => 12, 'max' => 84], $retention->limit('audit_months'));
        self::assertSame(['default' => 30, 'min' => 7, 'max' => 730], $retention->limit('sms_days'));
        self::assertTrue(TenantRetention::inMonths('audit_months'));
        self::assertFalse(TenantRetention::inMonths('sms_days'));
        $this->expectExceptionMessage('There is no retention "nope".');
        $retention->limit('nope');
    }

    /**
     * @return iterable<array{array<mixed>, string}>
     */
    public static function wrong(): iterable
    {
        yield [['nope' => 3], '"privacy.retention.nope" is not known'];
        yield [['sms_days' => '30'], '"privacy.retention.sms_days.default" must be a whole number'];
        yield [['audit_months' => ['min' => 3]], 'must keep its limits between 6 and 84'];
        yield [['audit_months' => ['max' => 100]], 'must keep its limits between 6 and 84'];
        yield [['audit_months' => ['min' => 30, 'max' => 20]], 'with min no more than max'];
        yield [['audit_months' => ['default' => 90]], 'must be between its min (6) and max (84)'];
        yield [['audit_months' => ['default' => 12, 'min' => 24]], '"privacy.retention.audit_months.default" must be between its min (24) and max (84)'];
    }

    /**
     * @param array<mixed> $section
     */
    #[DataProvider('wrong')]
    public function testRefusesWhatSightPressDoesntAllow(array $section, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);
        TenantRetention::fromArray($section);
    }
}
