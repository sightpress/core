<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Sms\Contract\TextMessageOptChanged;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Sms\Http\SmsWebhookHandler;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Sms\SmsOptOuts;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SmsWebhookHandler::class)]
#[CoversClass(SmsHttp::class)]
#[CoversClass(TextMessageOptChanged::class)]
#[UsesNamespace('SightPress\Core')]
final class SmsWebhookHandlerTest extends TestCase
{
    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private const string KEY = 'canary5f2b9c1d7e4a';

    private const string URL = 'https://example.org/_sms/webhook';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('sms_webhook', tenant: ['sms' => ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => self::KEY]);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(\DI\Container::class, $tenant);
        $tenant->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, new MockHttpClient(new MockResponse('{"sid":"SM1"}', ['http_code' => 201])));
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testRecordsReceiptsAndStopRepliesOnceAndAuditsThemMasked(): void
    {
        Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello'));

        $receipt = $this->request(['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM1', 'MessageStatus' => 'delivered']);
        self::assertSame([200, 'Received.'], self::answer($this->handler()->handle($receipt)));
        self::assertSame('delivered', Services::get($this->site->container(), SmsLog::class)->recent(1)[0]['status']);
        self::assertSame([200, 'Already received.'], self::answer($this->handler()->handle($receipt)), 'a replay changes nothing');

        $hash = Services::get($this->site->container(), SmsNumberHash::class)->of(new PhoneNumber('+447400123456'));
        self::assertSame([200, 'Received.'], self::answer($this->handler()->handle($this->request(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456', 'Body' => 'STOP']))));
        self::assertTrue(Services::get($this->site->container(), SmsOptOuts::class)->stopped($hash));
        $this->handler()->handle($this->request(['AccountSid' => self::ACCOUNT, 'From' => '+447400123456', 'Body' => 'START']));
        self::assertFalse(Services::get($this->site->container(), SmsOptOuts::class)->stopped($hash));

        $audit = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 10);
        $summaries = array_map(static fn ($entry): string => $entry->summary, $audit);
        self::assertContains('+44•••••••456 replied STOP: it gets no more text notifications.', $summaries);
        self::assertContains('+44•••••••456 replied START: it gets text notifications again.', $summaries);
        self::assertStringNotContainsString('7400123456', serialize($audit));
    }

    public function testRefusesBadSignaturesAndSitesWithoutWebhooks(): void
    {
        $forged = $this->request(['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM1', 'MessageStatus' => 'delivered'])->withHeader('X-Twilio-Signature', 'forged');
        $response = $this->handler()->handle($forged);
        self::assertSame([403, 'The signature is missing or wrong.'], self::answer($response));
        self::assertSame(['text/plain; charset=utf-8', 'no-store'], [$response->getHeaderLine('Content-Type'), $response->getHeaderLine('Cache-Control')]);

        Services::get($this->site->container(), SmsSettings::class)->connect('acme', null, null, null, null, null);
        self::assertSame(404, $this->handler()->handle($forged)->getStatusCode());
    }

    private function handler(): SmsWebhookHandler
    {
        return Services::get($this->site->container(), SmsWebhookHandler::class);
    }

    /**
     * @param array<string, string> $params
     */
    private function request(array $params): ServerRequest
    {
        ksort($params);
        $signed = self::URL;
        foreach ($params as $key => $value) {
            $signed .= $key . $value;
        }
        $body = new Psr17Factory()->createStream(http_build_query($params));

        return new ServerRequest('POST', self::URL, ['Content-Type' => 'application/x-www-form-urlencoded'], $body)
            ->withParsedBody($params)
            ->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $signed, self::KEY, true)));
    }

    /**
     * @return array{int, string}
     */
    private static function answer(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), (string) $response->getBody()];
    }
}
