<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsResult;

/**
 * A pretend text message service ("Test SMS") that never leaves the server: every number is a mobile number, and
 * every message is written to `texts.json` in the tenant's project folder, newest last, for the accessibility tests
 * to read back.
 */
final readonly class TestSmsProvider implements SmsProvider
{
    public const string ID = 'test_sms';

    public function __construct(private TenantConfig $config)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Test SMS';
    }

    public function description(): string
    {
        return 'A pretend text message service for tests. Nothing is sent anywhere.';
    }

    public function capabilities(): SmsCapabilities
    {
        return new SmsCapabilities(lookup: true);
    }

    public function send(SmsMessage $message, SmsConnection $connection): SmsResult
    {
        $file = $this->config->projectDir . '/texts.json';
        $texts = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $texts = is_array($texts) ? $texts : [];
        $texts[] = ['to' => $message->to->e164, 'text' => $message->text];
        file_put_contents($file, (string) json_encode($texts));

        return new SmsResult('test-' . count($texts), $message->parts());
    }

    public function lookup(PhoneNumber $number, SmsConnection $connection): PhoneLookupResult
    {
        return new PhoneLookupResult(true, PhoneLineType::Mobile);
    }
}
