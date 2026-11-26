<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsResult;
use SightPress\Module\Sms\SmsWebhooks;

/**
 * A pretend text message service ("Test SMS") that never leaves the server: every number is a mobile number, and
 * every message is written to `texts.json` in the tenant's project folder, newest last, for the accessibility tests
 * to read back. Its webhook takes a reply as JSON (`{"from": "+447…", "text": "STOP"}`), signed with the key in
 * `X-Test-Sms-Signature` (HMAC-SHA256 of the body), as a real provider would send it.
 */
final readonly class TestSmsProvider implements SmsProvider, SmsWebhooks
{
    public const string SIGNATURE = 'X-Test-Sms-Signature';

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
        return new SmsCapabilities(lookup: true, optOutReplies: true);
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

    public function readWebhook(ServerRequestInterface $request, SmsConnection $connection): array
    {
        $body = (string) $request->getBody();
        if (!hash_equals(hash_hmac('sha256', $body, $connection->key), $request->getHeaderLine(self::SIGNATURE))) {
            throw new SmsException(SmsFailure::InvalidSignature, 'The signature is missing or wrong.');
        }
        $reply = json_decode($body, true);
        $from = is_array($reply) && is_string($reply['from'] ?? null) ? $reply['from'] : '';
        $text = is_array($reply) && is_string($reply['text'] ?? null) ? strtoupper(trim($reply['text'])) : '';
        if ($from === '' || !in_array($text, ['STOP', 'START'], true)) {
            return [];
        }

        return [new SmsOptChange(new PhoneNumber($from), $text === 'STOP', new DateTimeImmutable('now', new DateTimeZone('UTC')))];
    }
}
