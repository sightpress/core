<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Provider;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Sms\Provider\TwilioProvider;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsPurpose;
use SightPress\Module\Sms\SmsReceipt;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TwilioProvider::class)]
#[CoversClass(SmsHttp::class)]
final class TwilioProviderTest extends SmsProviderTestCase
{
    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private const string WEBHOOK = 'https://example.org/_sms/webhook';

    public function testDescribesItself(): void
    {
        $provider = $this->provider([]);
        self::assertSame(['twilio', 'Twilio'], [$provider->id(), $provider->label()]);
        self::assertStringContainsString('United States', $provider->description());
        $capabilities = $provider->capabilities();
        self::assertTrue($capabilities->lookup && $capabilities->deliveryReceipts && $capabilities->optOutReplies && $capabilities->alphanumericSender);
    }

    public function testSendsAMessageWithBasicAuth(): void
    {
        $provider = $this->provider([self::json(['sid' => 'SM123', 'num_segments' => '2', 'status' => 'queued'], 201)]);
        $result = $provider->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Your code is 123456', SmsPurpose::SecurityCode), $this->connection('SightPress'));

        self::assertSame(['SM123', 2], [$result->messageId, $result->parts]);
        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://api.twilio.com/2010-04-01/Accounts/' . self::ACCOUNT . '/Messages.json', $this->sent[0]['url']);
        self::assertSame(['To' => '+447400123456', 'Body' => 'Your code is 123456', 'From' => 'SightPress'], $this->form());
        self::assertContains('Authorization: Basic ' . base64_encode(self::ACCOUNT . ':' . self::CANARY), $this->sent[0]['headers']);

        $this->sent = [];
        $result = $this->provider([self::json(['sid' => 'SM124'], 201)])->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello'), $this->connection('+447400000001'));
        self::assertSame(['SM124', 1], [$result->messageId, $result->parts]);
        self::assertSame('+447400000001', $this->form()['From']);
    }

    public function testMapsErrors(): void
    {
        $message = new SmsMessage(new PhoneNumber('+447400123456'), 'Hello');
        foreach ([
            [20003, 401, SmsFailure::InvalidKey],
            [21211, 400, SmsFailure::InvalidNumber],
            [21408, 400, SmsFailure::UnsupportedCountry],
            [21610, 400, SmsFailure::OptedOut],
            [20429, 429, SmsFailure::RateLimited],
            [30044, 400, SmsFailure::NoCredit],
            [99999, 402, SmsFailure::NoCredit],
            [99999, 500, SmsFailure::Unavailable],
        ] as [$code, $status, $failure]) {
            $responses = [self::json(['code' => $code, 'message' => 'The number +447400123456 …', 'status' => $status], $status)];
            if ($status === 429) {
                $responses = [...$responses, ...$responses, ...$responses];
            }
            $provider = $this->provider($responses);
            $exception = self::failure(fn (): mixed => $provider->send($message, $this->connection('SightPress')));
            self::assertSame($failure, $exception->failure, (string) $code);
        }
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $this->provider([new MockResponse('<html>Bad gateway</html>', ['http_code' => 502])])->send($message, $this->connection('SightPress')))->failure, 'not JSON');
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $this->provider([self::json(['status' => 'queued'], 201)])->send($message, $this->connection('SightPress')))->failure, 'no sid');
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $this->provider([])->send($message, $this->connection(null)))->failure, 'no sender');
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $this->provider([])->send($message, new SmsConnection(self::CANARY, 'not-a-sid', sender: 'SightPress')))->failure, 'no account SID');
        self::assertSame(SmsFailure::Timeout, self::failure(fn (): mixed => $this->provider([static fn (): never => throw new TimeoutException('Idle timeout reached.')])->send($message, $this->connection('SightPress')))->failure);
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $this->provider([static fn (): never => throw new TransportException('down')])->send($message, $this->connection('SightPress')))->failure);
    }

    public function testLooksUpTheLineType(): void
    {
        $number = new PhoneNumber('+447400123456');
        $provider = $this->provider([
            self::json(['valid' => true, 'country_code' => 'GB', 'line_type_intelligence' => ['type' => 'mobile']]),
            self::json(['valid' => true, 'country_code' => 'GB', 'line_type_intelligence' => ['type' => 'landline']]),
            self::json(['valid' => true, 'line_type_intelligence' => ['type' => 'nonFixedVoip']]),
            self::json(['valid' => true, 'line_type_intelligence' => ['type' => 'tollFree']]),
            self::json(['valid' => true, 'line_type_intelligence' => ['type' => 'premium']]),
            self::json(['valid' => false, 'country_code' => 'nowhere']),
            self::json([], 404),
            self::json(['code' => 20003], 401),
        ]);
        $mobile = $provider->lookup($number, $this->connection('SightPress'));
        self::assertTrue($mobile->isMobile());
        self::assertSame('GB', $mobile->country);
        self::assertSame('https://lookups.twilio.com/v2/PhoneNumbers/%2B447400123456?Fields=line_type_intelligence', $this->sent[0]['url']);
        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame(PhoneLineType::Landline, $provider->lookup($number, $this->connection('SightPress'))->lineType);
        self::assertSame(PhoneLineType::Voip, $provider->lookup($number, $this->connection('SightPress'))->lineType);
        self::assertSame(PhoneLineType::TollFree, $provider->lookup($number, $this->connection('SightPress'))->lineType);
        self::assertSame(PhoneLineType::PremiumRate, $provider->lookup($number, $this->connection('SightPress'))->lineType);
        $invalid = $provider->lookup($number, $this->connection('SightPress'));
        self::assertSame([false, PhoneLineType::Unknown, null], [$invalid->valid, $invalid->lineType, $invalid->country]);
        self::assertFalse($provider->lookup($number, $this->connection('SightPress'))->valid, '404');
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $provider->lookup($number, $this->connection('SightPress')))->failure);
    }

    public function testReadsSignedStatusCallbacksAndReplies(): void
    {
        $provider = $this->provider([]);
        $connection = $this->connection('SightPress');

        $delivered = $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM123', 'MessageStatus' => 'delivered']), $connection);
        self::assertCount(1, $delivered);
        self::assertInstanceOf(SmsReceipt::class, $delivered[0]);
        self::assertSame(['SM123', SmsDeliveryStatus::Delivered, null], [$delivered[0]->messageId, $delivered[0]->status, $delivered[0]->reason]);
        $failed = $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM124', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003']), $connection);
        self::assertInstanceOf(SmsReceipt::class, $failed[0]);
        self::assertSame([SmsDeliveryStatus::Failed, 'twilio_30003'], [$failed[0]->status, $failed[0]->reason]);
        self::assertSame([], $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM125', 'MessageStatus' => 'sent']), $connection));

        $stop = $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456', 'Body' => ' stop please']), $connection);
        self::assertInstanceOf(SmsOptChange::class, $stop[0]);
        self::assertSame(['+447400123456', true], [$stop[0]->from->e164, $stop[0]->stopped]);
        $start = $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456', 'Body' => 'Hi', 'OptOutType' => 'START']), $connection);
        self::assertInstanceOf(SmsOptChange::class, $start[0]);
        self::assertFalse($start[0]->stopped);
        self::assertSame([], $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456', 'Body' => 'Thanks']), $connection));
        self::assertSame([], $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'From' => 'short', 'Body' => 'STOP']), $connection));
        self::assertSame([], $provider->readWebhook($this->webhook(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456']), $connection));
    }

    public function testRefusesUnsignedOrForgedWebhooks(): void
    {
        $provider = $this->provider([]);
        $connection = $this->connection('SightPress');
        $params = ['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];

        $forged = $this->webhook($params)->withParsedBody(['MessageStatus' => 'failed'] + $params);
        self::assertSame(SmsFailure::InvalidSignature, self::failure(static fn (): mixed => $provider->readWebhook($forged, $connection))->failure);
        self::assertSame(SmsFailure::InvalidSignature, self::failure(fn (): mixed => $provider->readWebhook($this->webhook($params)->withoutHeader('X-Twilio-Signature'), $connection))->failure);
        $other = ['AccountSid' => 'AC' . str_repeat('f', 32)] + $params;
        self::assertSame(SmsFailure::InvalidSignature, self::failure(fn (): mixed => $provider->readWebhook($this->webhook($other), $connection))->failure, 'another account');
        self::assertSame(SmsFailure::InvalidSignature, self::failure(fn (): mixed => $provider->readWebhook($this->webhook($params)->withParsedBody(null), $connection))->failure);
    }

    /**
     * @param list<MockResponse|\Closure(): never> $responses
     */
    private function provider(array $responses): TwilioProvider
    {
        return new TwilioProvider($this->http($responses), new FixedClock(new \DateTimeImmutable('2026-11-25 10:00:00')));
    }

    private function connection(?string $sender): SmsConnection
    {
        return new SmsConnection(self::CANARY, self::ACCOUNT, sender: $sender);
    }

    /**
     * @param array<string, string> $params
     */
    private function webhook(array $params): ServerRequest
    {
        ksort($params);
        $signed = self::WEBHOOK;
        foreach ($params as $key => $value) {
            $signed .= $key . $value;
        }

        return new ServerRequest('POST', self::WEBHOOK)
            ->withParsedBody($params)
            ->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $signed, self::CANARY, true)));
    }
}
