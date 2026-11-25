<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Provider;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Sms\Provider\VonageProvider;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsReceipt;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(VonageProvider::class)]
#[CoversClass(SmsHttp::class)]
final class VonageProviderTest extends SmsProviderTestCase
{
    private const string API_KEY = 'abcd1234';

    private const string SIGNATURE = 'signature9f8e7d6c';

    private const int NOW = 1795600800;

    public function testDescribesItself(): void
    {
        $provider = $this->provider([]);
        self::assertSame(['vonage', 'Vonage'], [$provider->id(), $provider->label()]);
        self::assertStringContainsString('EU', $provider->description());
        self::assertTrue($provider->capabilities()->lookup);
    }

    public function testSendsAMessageWithTheKeyAndSecret(): void
    {
        $provider = $this->provider([
            self::json(['message-count' => '1', 'messages' => [['status' => '0', 'message-id' => 'V1']]]),
            self::json(['message-count' => '2', 'messages' => [['status' => '0', 'message-id' => 'V2'], ['status' => '0', 'message-id' => 'V3']]]),
        ]);
        $result = $provider->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello', reference: 'sms-1'), $this->connection());
        self::assertSame(['V1', 1], [$result->messageId, $result->parts]);
        self::assertSame(['POST', 'https://rest.nexmo.com/sms/json'], [$this->sent[0]['method'], $this->sent[0]['url']]);
        self::assertSame(['api_key' => self::API_KEY, 'api_secret' => self::CANARY, 'from' => 'SightPress', 'to' => '447400123456', 'text' => 'Hello', 'client-ref' => 'sms-1'], $this->form());

        $result = $provider->send(new SmsMessage(new PhoneNumber('+447400123456'), str_repeat('Olá 😀 ', 12)), $this->connection('+447400000001'));
        self::assertSame(['V2', 2], [$result->messageId, $result->parts]);
        self::assertSame(['unicode', '447400000001'], [$this->form(1)['type'], $this->form(1)['from']]);
    }

    public function testMapsErrors(): void
    {
        $message = new SmsMessage(new PhoneNumber('+447400123456'), 'Hello');
        foreach (['1' => SmsFailure::RateLimited, '3' => SmsFailure::InvalidNumber, '4' => SmsFailure::InvalidKey, '7' => SmsFailure::OptedOut, '9' => SmsFailure::NoCredit, '29' => SmsFailure::UnsupportedCountry, '99' => SmsFailure::Unavailable] as $code => $failure) {
            $provider = $this->provider([self::json(['messages' => [['status' => (string) $code, 'error-text' => 'to 447400123456']]])]);
            self::assertSame($failure, self::failure(fn (): mixed => $provider->send($message, $this->connection()))->failure, (string) $code);
        }
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $this->provider([self::json(['messages' => 'none'])])->send($message, $this->connection()))->failure);
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $this->provider([self::json(['messages' => [['status' => '0']]])])->send($message, $this->connection()))->failure, 'no id');
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $this->provider([self::json([], 401)])->send($message, $this->connection()))->failure);
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $this->provider([])->send($message, new SmsConnection(self::CANARY, self::API_KEY)))->failure, 'no sender');
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $this->provider([])->send($message, new SmsConnection(self::CANARY, null, sender: 'SightPress')))->failure, 'no API key');
    }

    public function testLooksUpTheNetworkType(): void
    {
        $number = new PhoneNumber('+447400123456');
        $answers = [];
        foreach (['mobile', 'landline', 'landline_premium', 'landline_tollfree', 'virtual', 'pager'] as $type) {
            $answers[] = self::json(['status' => 0, 'country_code' => 'GB', 'current_carrier' => ['network_type' => $type]]);
        }
        $provider = $this->provider([...$answers, self::json(['status' => 3]), self::json(['status' => 4]), self::json(['status' => 0, 'country_code' => 'gb']), self::json([], 500)]);
        $mobile = $provider->lookup($number, $this->connection());
        self::assertTrue($mobile->isMobile());
        self::assertSame('GB', $mobile->country);
        self::assertSame('https://api.nexmo.com/ni/standard/json?api_key=' . self::API_KEY . '&api_secret=' . self::CANARY . '&number=447400123456', $this->sent[0]['url']);
        foreach ([PhoneLineType::Landline, PhoneLineType::PremiumRate, PhoneLineType::TollFree, PhoneLineType::Voip, PhoneLineType::Unknown] as $type) {
            self::assertSame($type, $provider->lookup($number, $this->connection())->lineType);
        }
        self::assertFalse($provider->lookup($number, $this->connection())->valid);
        self::assertSame(SmsFailure::InvalidKey, self::failure(fn (): mixed => $provider->lookup($number, $this->connection()))->failure);
        self::assertNull($provider->lookup($number, $this->connection())->country);
        self::assertSame(SmsFailure::Unavailable, self::failure(fn (): mixed => $provider->lookup($number, $this->connection()))->failure);
    }

    public function testReadsSignedReceiptsAndReplies(): void
    {
        $provider = $this->provider([]);
        $connection = $this->connection();

        $delivered = $provider->readWebhook($this->webhook(['messageId' => 'V1', 'status' => 'delivered', 'msisdn' => '447400123456']), $connection);
        self::assertInstanceOf(SmsReceipt::class, $delivered[0]);
        self::assertSame(['V1', SmsDeliveryStatus::Delivered], [$delivered[0]->messageId, $delivered[0]->status]);
        $failed = $provider->readWebhook($this->webhook(['messageId' => 'V2', 'status' => 'rejected', 'err-code' => '6']), $connection);
        self::assertInstanceOf(SmsReceipt::class, $failed[0]);
        self::assertSame([SmsDeliveryStatus::Failed, 'vonage_6'], [$failed[0]->status, $failed[0]->reason]);
        $expired = $provider->readWebhook($this->webhook(['messageId' => 'V3', 'status' => 'expired']), $connection);
        self::assertInstanceOf(SmsReceipt::class, $expired[0]);
        self::assertSame('vonage_expired', $expired[0]->reason);
        self::assertSame([], $provider->readWebhook($this->webhook(['messageId' => 'V4', 'status' => 'accepted']), $connection));

        $stop = $provider->readWebhook($this->webhook(['msisdn' => '447400123456', 'text' => 'Stop now', 'api-key' => self::API_KEY], query: true), $connection);
        self::assertInstanceOf(SmsOptChange::class, $stop[0]);
        self::assertSame(['+447400123456', true], [$stop[0]->from->e164, $stop[0]->stopped]);
        $start = $provider->readWebhook($this->webhook(['msisdn' => '447400123456', 'keyword' => 'start', 'text' => 'start']), $connection);
        self::assertInstanceOf(SmsOptChange::class, $start[0]);
        self::assertFalse($start[0]->stopped);
        self::assertSame([], $provider->readWebhook($this->webhook(['msisdn' => '447400123456', 'text' => 'Hello & = there']), $connection));
        self::assertSame([], $provider->readWebhook($this->webhook(['msisdn' => '', 'text' => 'STOP']), $connection));
    }

    public function testRefusesUnsignedForgedAndOldWebhooks(): void
    {
        $provider = $this->provider([]);
        $connection = $this->connection();
        $params = ['messageId' => 'V1', 'status' => 'delivered'];

        $forged = $this->webhook($params)->withParsedBody(['status' => 'failed', 'messageId' => 'V1', 'timestamp' => (string) self::NOW, 'sig' => 'x']);
        foreach ([
            $forged,
            $this->webhook($params, timestamp: self::NOW - VonageProvider::MAX_AGE - 1),
            $this->webhook($params + ['api-key' => 'other123']),
            $this->webhook($params, secret: 'wrong'),
        ] as $request) {
            self::assertSame(SmsFailure::InvalidSignature, self::failure(static fn (): mixed => $provider->readWebhook($request, $connection))->failure);
        }
        self::assertSame(SmsFailure::InvalidSignature, self::failure(fn (): mixed => $provider->readWebhook($this->webhook($params), new SmsConnection(self::CANARY, self::API_KEY, sender: 'SightPress')))->failure, 'no signature secret');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(array $responses): VonageProvider
    {
        return new VonageProvider($this->http($responses), new FixedClock(new \DateTimeImmutable('@' . self::NOW)));
    }

    private function connection(string $sender = 'SightPress'): SmsConnection
    {
        return new SmsConnection(self::CANARY . '|' . self::SIGNATURE, self::API_KEY, sender: $sender);
    }

    /**
     * @param array<string, string> $params
     */
    private function webhook(array $params, int $timestamp = self::NOW, string $secret = self::SIGNATURE, bool $query = false): ServerRequest
    {
        $params['timestamp'] = (string) $timestamp;
        ksort($params);
        $signed = '';
        foreach ($params as $key => $value) {
            $signed .= '&' . $key . '=' . str_replace(['&', '='], '_', $value);
        }
        $params['sig'] = strtolower(hash_hmac('sha256', $signed, $secret));
        $request = new ServerRequest($query ? 'GET' : 'POST', 'https://example.org/_sms/webhook');

        return $query ? $request->withQueryParams($params) : $request->withParsedBody($params);
    }
}
