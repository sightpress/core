<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantSms;

#[CoversClass(TenantSms::class)]
final class TenantSmsTest extends TestCase
{
    public function testDefaultsToNoProviderAndSafeLimits(): void
    {
        $sms = TenantSms::fromArray([]);
        self::assertFalse($sms->hasDefault());
        self::assertSame([null, null, false, false, 1000, 50, null, TenantSms::LIMITS], [$sms->provider, $sms->key, $sms->siteProviders, $sms->requireForExisting, $sms->monthlyBudget, $sms->reservedForCodes, $sms->countries, $sms->limits]);
        self::assertEquals($sms, new TenantSms());
    }

    public function testReadsTheSectionAndTheKeyFromTheEnvironment(): void
    {
        $sms = TenantSms::fromArray([
            'provider' => 'twilio',
            'account' => ' AC1 ',
            'sender' => 'SightPress',
            'region' => '',
            'site_providers' => true,
            'require_for_existing' => true,
            'monthly_budget' => 0,
            'reserved_for_codes' => 10,
            'countries' => ['gb', 'IE', 'GB'],
            'limits' => ['per_number' => 2, 'lookups_per_day' => 20],
        ], [TenantSms::KEY_VARIABLE => ' secret ']);

        self::assertTrue($sms->hasDefault());
        self::assertSame(['twilio', 'AC1', 'secret', 'SightPress', null, true, true, 0, 10, ['GB', 'IE']], [$sms->provider, $sms->account, $sms->key, $sms->sender, $sms->region, $sms->siteProviders, $sms->requireForExisting, $sms->monthlyBudget, $sms->reservedForCodes, $sms->countries]);
        self::assertSame(['per_number' => 2, 'per_client' => 10, 'per_account' => 5, 'per_site' => 100, 'lookups_per_day' => 20], $sms->limits);
        self::assertNull(TenantSms::fromArray([], [TenantSms::KEY_VARIABLE => ' '])->key);
    }

    /**
     * @param array<mixed> $section
     */
    #[DataProvider('invalid')]
    public function testRefusesInvalidSettings(array $section, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);
        TenantSms::fromArray($section);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'budget' => [['monthly_budget' => -1], '"sms.monthly_budget" must be a whole number of message parts'];
        yield 'reserve' => [['reserved_for_codes' => 'ten'], '"sms.reserved_for_codes" must be a whole number'];
        yield 'reserve too big' => [['monthly_budget' => 10, 'reserved_for_codes' => 10], 'must be smaller than "sms.monthly_budget"'];
        yield 'countries' => [['countries' => ['GBR']], '"sms.countries" must list countries as two-letter codes'];
        yield 'empty countries' => [['countries' => []], '"sms.countries" must list countries'];
        yield 'limits' => [['limits' => 5], '"sms.limits" must be an array'];
        yield 'unknown limit' => [['limits' => ['per_day' => 5]], 'unknown limit "per_day"'];
        yield 'bad limit' => [['limits' => ['per_site' => 0]], '"sms.limits.per_site" must be a whole number, at least 1'];
        yield 'text' => [['provider' => 5], '"sms.provider" must be text, or null'];
        yield 'flag' => [['site_providers' => 'yes'], '"sms.site_providers" must be true or false'];
    }
}
