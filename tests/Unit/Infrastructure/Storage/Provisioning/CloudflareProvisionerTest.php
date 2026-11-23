<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Storage\Provisioning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Infrastructure\Storage\Provisioning\CloudflareProvisioner;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningRequest;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;
use SightPress\Core\Tests\Support\FakeAws;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(CloudflareProvisioner::class)]
#[UsesNamespace('SightPress\Core')]
final class CloudflareProvisionerTest extends TestCase
{
    private const string BASE = 'https://api.cloudflare.com/client/v4/accounts/acc/r2/buckets';

    /** @var list<array{0: string, 1: string, 2: array<array-key, mixed>, 3: array<array-key, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{0: int, 1: string}> "METHOD /path" => status and body */
    private array $answers = [];

    /**
     * @param array<string, list<string>>|null $hosts
     */
    private function provisioner(?string $zone = 'zone-1', ?array $hosts = null): CloudflareProvisioner
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $path = substr($url, strlen(self::BASE));
            $body = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : [];
            $this->calls[] = [$method, $path, is_array($body) ? $body : [], $options];
            $key = $method . ' ' . $path;
            if (($this->answers[$key][0] ?? null) === 0) {
                throw new TransportException('down');
            }
            [$status, $content] = $this->answers[$key] ?? [200, '{"success":true,"result":{}}'];

            return new MockResponse($content, ['http_code' => $status]);
        });
        $guard = new EndpointGuard(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new FixedResolver($hosts ?? ['api.cloudflare.com' => ['104.16.132.229']]));

        return new CloudflareProvisioner($client, $guard, 'acc', 'token-value', $zone, 0);
    }

    private static function request(?string $domain = null): ProvisioningRequest
    {
        return new ProvisioningRequest('media-bucket', 'eu', 'sites/one', ['https://demo.example'], 'demo.example', $domain);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function sent(string $method, string $path): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call[0] === $method && $call[1] === $path) {
                return $call[2];
            }
        }
        self::fail(sprintf('%s %s was not called.', $method, $path));
    }

    public function testPlanNamesThePublicBucketAndContactsNobody(): void
    {
        $plan = $this->provisioner()->plan(self::request('files.example.org'));
        self::assertSame('EU', $plan->location);
        self::assertCount(4, $plan->steps);
        self::assertStringContainsString('media-bucket-public', (string) $plan->steps[3]);
        self::assertSame([], $this->calls);
        self::assertCount(3, $this->provisioner()->plan(self::request())->steps);
    }

    public function testCreatesBucketRulesAndKeepsTheDomainOffThePrivateBucket(): void
    {
        $this->answers = ['GET /media-bucket' => [404, '{}'], 'GET /media-bucket-public' => [404, '{}'], 'GET /media-bucket/cors' => [404, '{}'], 'GET /media-bucket/lifecycle' => [404, '{}']];

        $result = $this->provisioner()->provision(self::request('files.example.org'));

        self::assertSame(['bucket', 'public bucket', 'custom domain'], $result->created);
        self::assertSame('files.example.org', $result->cdnHost);
        self::assertSame(['name' => 'media-bucket-public'], $this->sent('POST', ''));
        $cors = FakeAws::dig($this->sent('PUT', '/media-bucket/cors'), 'rules', 0);
        self::assertSame(['PUT'], FakeAws::dig($cors, 'allowed', 'methods'));
        self::assertSame(['https://demo.example'], FakeAws::dig($cors, 'allowed', 'origins'));
        self::assertSame(['ETag'], FakeAws::dig($cors, 'exposeHeaders'));
        $rules = FakeAws::dig($this->sent('PUT', '/media-bucket/lifecycle'), 'rules');
        self::assertSame(86400, FakeAws::dig($rules, 0, 'abortMultipartUploadsTransition', 'condition', 'maxAge'));
        self::assertSame('sites/one/incoming/', FakeAws::dig($rules, 1, 'conditions', 'prefix'));
        self::assertSame(172800, FakeAws::dig($rules, 1, 'deleteObjectsTransition', 'condition', 'maxAge'));
        self::assertSame(['domain' => 'files.example.org', 'zoneId' => 'zone-1', 'enabled' => true, 'minTLS' => '1.2'], $this->sent('POST', '/media-bucket-public/domains/custom'));
        self::assertNotContains('/media-bucket/domains/custom', array_column($this->calls, 1));
        foreach ($this->calls as [, , , $options]) {
            self::assertSame(0, FakeAws::dig($options, 'max_redirects'));
            self::assertSame(['api.cloudflare.com' => '104.16.132.229'], FakeAws::dig($options, 'resolve'));
            self::assertContains('cf-r2-jurisdiction: eu', FakeAws::items(FakeAws::dig($options, 'headers')));
        }
    }

    public function testRunningAgainKeepsOtherRulesAndCreatesNothing(): void
    {
        $tag = substr(hash('sha256', 'sites/one'), 0, 32);
        $this->answers = [
            'GET /media-bucket/cors' => [200, (string) json_encode(['result' => ['rules' => [['id' => 'theirs'], ['id' => CloudflareProvisioner::CORS_ID], 'junk']]])],
            'GET /media-bucket/lifecycle' => [200, (string) json_encode(['result' => ['rules' => [['id' => 'theirs'], ['id' => CloudflareProvisioner::ABORT_RULE . '-' . $tag]]]])],
            'GET /media-bucket-public/domains/custom' => [200, (string) json_encode(['result' => ['domains' => [['domain' => 'files.example.org']]]])],
        ];

        $result = $this->provisioner()->provision(self::request('files.example.org'));

        self::assertSame([], $result->created);
        self::assertSame(['bucket', 'public bucket', 'custom domain'], $result->unchanged);
        self::assertNotContains('POST', array_column($this->calls, 0));
        self::assertSame(['theirs', CloudflareProvisioner::CORS_ID], array_column(FakeAws::items(FakeAws::dig($this->sent('PUT', '/media-bucket/cors'), 'rules')), 'id'));
        self::assertCount(3, FakeAws::items(FakeAws::dig($this->sent('PUT', '/media-bucket/lifecycle'), 'rules')));
    }

    public function testWithoutADomainNoPublicBucketIsMade(): void
    {
        $result = $this->provisioner()->provision(self::request());
        self::assertNull($result->cdnHost);
        self::assertNotContains('/media-bucket-public', array_column($this->calls, 1));
    }

    public function testACustomDomainNeedsAZone(): void
    {
        $this->expectExceptionMessage('SIGHTPRESS_CLOUDFLARE_ZONE_ID');
        $this->provisioner(null)->provision(self::request('files.example.org'));
    }

    public function testOddRuleAnswersCountAsNone(): void
    {
        $this->answers = ['GET /media-bucket/cors' => [200, '{"result":"none"}'], 'GET /media-bucket/lifecycle' => [200, '{"result":{"rules":"none"}}'], 'GET /media-bucket' => [200, '"text"']];
        $this->provisioner()->provision(self::request());
        self::assertCount(1, FakeAws::items(FakeAws::dig($this->sent('PUT', '/media-bucket/cors'), 'rules')));
        self::assertCount(2, FakeAws::items(FakeAws::dig($this->sent('PUT', '/media-bucket/lifecycle'), 'rules')));
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function failures(): iterable
    {
        yield 'token' => [401, '{"errors":[{"message":"token-value bad"}]}', 'API token'];
        yield 'forbidden' => [403, '{}', 'API token'];
        yield 'busy' => [429, '{}', 'busy'];
        yield 'other' => [400, '{"errors":[{"message":"token-value bad"}]}', 'HTTP 400'];
        yield 'not json' => [200, '<html>', 'can\'t be read'];
        yield 'network' => [0, '', 'can\'t be reached'];
    }

    #[DataProvider('failures')]
    public function testFailuresArePlainAndNeverShowTheToken(int $status, string $body, string $expected): void
    {
        $this->answers = ['GET /media-bucket' => [$status, $body]];
        try {
            $this->provisioner()->provision(self::request());
            self::fail('It should have failed.');
        } catch (ProvisioningException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringNotContainsString('token-value', $exception->getMessage());
        }
    }

    public function testARefusedAddressIsNeverCalled(): void
    {
        try {
            $this->provisioner(hosts: ['api.cloudflare.com' => ['10.0.0.1']])->provision(self::request());
            self::fail('It should have failed.');
        } catch (ProvisioningException $exception) {
            self::assertStringContainsString('can\'t be reached', $exception->getMessage());
        }
        self::assertSame([], $this->calls);
    }
}
