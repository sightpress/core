<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Privacy\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Privacy\Http\PrivacySettingsHandler;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Text;
use SightPress\Core\User\Entity\User;
use SightPress\Module\Event\SettingsChanged;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(PrivacySettingsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PrivacySettingsHandlerTest extends TestCase
{
    private AdminFixture $fixture;

    private RecordingEvents $events;

    private Session $session;

    private User $admin;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->events = new RecordingEvents();
        $this->session = new Session();
        $this->admin = $this->fixture->auth->user('ada@example.org', 'admin');
    }

    public function testOnlyPeopleWhoManagePrivacyMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->handle('GET', user: $this->fixture->auth->user('bea@example.org', 'editor'));
    }

    public function testOffersEachRetentionWithinTheTenantsLimits(): void
    {
        $html = (string) $this->handle('GET', tenant: ['privacy' => ['retention' => ['audit_months' => ['default' => 36, 'min' => 12, 'max' => 48]]]])->getBody();

        self::assertStringContainsString('<h1>Privacy</h1>', $html);
        self::assertStringContainsString('<label for="privacy-audit-months">Audit log</label>', $html);
        self::assertStringContainsString('Entries older than this are deleted.', $html);
        self::assertStringContainsString('<option value="36" selected>36 months (suggested)</option>', $html);
        self::assertStringContainsString('<option value="12">12 months</option>', $html);
        self::assertStringNotContainsString('<option value="84">', $html);
        self::assertStringContainsString('<option value="90" selected>90 days (suggested)</option>', $html);
        self::assertStringContainsString('<option value="1" selected>1 day (suggested)</option>', $html);
        self::assertStringContainsString('This isn’t legal advice.', $html);
    }

    public function testSavesAndAuditsAChangeAndRefusesAValueOutsideTheLimits(): void
    {
        $values = ['audit_months' => '24', 'unverified_days' => '7', 'invitation_days' => '30', 'code_days' => '1', 'sms_days' => '30', 'assistant_months' => '13'];

        $saved = $this->handle('POST', $values);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame(['How long personal data is kept has been saved. Older records are deleted within a day.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertInstanceOf(SettingsChanged::class, $this->events->events[0] ?? null);
        self::assertSame(['privacy.retention.sms_days'], $this->events->events[0]->keys);

        self::assertSame(303, $this->handle('POST', $values)->getStatusCode());
        self::assertSame(['Nothing was changed.'], Text::of(array_map(strval(...), Flash::take($this->session))));

        $refused = $this->handle('POST', ['sms_days' => '1'] + $values);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('error-summary', (string) $refused->getBody());
    }

    /**
     * @param array<string, string> $body
     * @param array<mixed>          $tenant
     */
    private function handle(string $method, array $body = [], ?User $user = null, array $tenant = []): ResponseInterface
    {
        $auth = $this->fixture->auth;
        $retention = new RetentionPolicy($auth->database->settings(), TenantConfig::fromArray($tenant, '/tmp/project'), $this->events);

        return new PrivacySettingsHandler($this->fixture->shell(), $auth->validator(), $retention)
            ->handle($this->fixture->request($user ?? $this->admin, $method, PrivacySettingsHandler::PATH, $body, $this->session));
    }
}
