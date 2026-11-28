<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Sms;

use SightPress\Core\Infrastructure\Http\OutboundHttp;
use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The provider's webhook through the whole kernel (N-2): 404 while text messages are off, no session or CSRF token
 * needed while they are on (also on a members-only site), a forged signature refused, and a replay ignored.
 */
#[CoversNothing]
final class SmsWebhookFlowTest extends TestCase
{
    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private const string KEY = 'canary5f2b9c1d7e4a';

    private const string URL = 'http://example.org/_sms/webhook';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('sms_webhook_flow', tenant: ['sms' => ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => self::KEY]);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(OutboundHttp::INNER, new MockHttpClient(new MockResponse('{"sid":"SM1"}', ['http_code' => 201])));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheWebhookOnlyAnswersWhileTextMessagesAreOn(): void
    {
        $receipt = ['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM1', 'MessageStatus' => 'delivered'];
        self::assertSame(404, $this->post($receipt)[0], 'off: no route');

        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        Services::get($this->site->container(), SiteSettings::class)->set('site.visibility', 'members');
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        Services::get($this->site->container(), SmsSender::class)->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Hello'));

        self::assertSame([403, 'The signature is missing or wrong.'], $this->post($receipt, forged: true));
        self::assertSame([200, 'Received.'], $this->post($receipt));
        self::assertSame([200, 'Already received.'], $this->post($receipt));
        self::assertSame('delivered', Services::get($this->site->container(), SmsLog::class)->recent(1)[0]['status']);
        self::assertSame(404, $this->get(), 'only POST: a GET finds no page');
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{int, string}
     */
    private function post(array $params, bool $forged = false): array
    {
        ksort($params);
        $signed = self::URL;
        foreach ($params as $key => $value) {
            $signed .= $key . $value;
        }
        $request = new ServerRequest('POST', self::URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Twilio-Signature' => $forged ? 'forged' : base64_encode(hash_hmac('sha1', $signed, self::KEY, true))], new Psr17Factory()->createStream(http_build_query($params)), '1.1', ['REMOTE_ADDR' => '203.0.113.5']);
        $response = $this->site->kernel->handle($request->withParsedBody($params));

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    private function get(): int
    {
        return $this->site->kernel->handle(new ServerRequest('GET', self::URL, [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.5']))->getStatusCode();
    }
}
