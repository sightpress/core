<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\Http\SignUpSettingsHandler;
use SightPress\Core\Rbac\Contract\SignUpRoles;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Text;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;

#[CoversClass(SignUpSettingsHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class SignUpSettingsHandlerTest extends TestCase
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
        $this->fixture->auth->database->manager()->create('exporter', 'Exporter', ['audit.view']);
    }

    public function testOnlyPeopleWhoManageSettingsMayUseIt(): void
    {
        $this->expectException(HttpForbiddenException::class);

        $this->handle('GET', user: $this->fixture->auth->user('bea@example.org', 'editor'));
    }

    public function testSaysWhatIsSetAndOffersOnlySignUpSafeRoles(): void
    {
        $response = $this->handle('GET');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Sign-up - Admin - Demo</title>', $html);
        self::assertStringContainsString('<h1>Sign-up</h1>', $html);
        self::assertStringContainsString('<a href="/admin/settings">Back to site settings</a>', $html);
        self::assertStringContainsString('Only people you invite can join. Nobody can create an account on their own.', $html);
        self::assertStringContainsString('<input type="checkbox" id="sign-up-open" value="1" name="open"', $html);
        self::assertStringNotContainsString('value="1" checked name="open"', $html);
        self::assertStringContainsString('<option value="member" selected>Member</option>', $html);
        self::assertStringContainsString('<option value="contributor">Contributor</option>', $html);
        foreach (['admin', 'editor', 'exporter'] as $unsafe) {
            self::assertStringNotContainsString('<option value="' . $unsafe . '"', $html);
        }
    }

    public function testOpensSignUpWithAnotherSafeRoleAndSaysSo(): void
    {
        $response = $this->handle('POST', ['open' => '1', 'role' => 'contributor']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/settings/sign-up', $response->getHeaderLine('Location'));
        self::assertSame(['The sign-up settings have been saved.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame('1', $this->fixture->auth->database->settings()->get(SignUp::SETTING));
        self::assertSame('contributor', $this->fixture->auth->database->registrationRole()->name());
        self::assertEquals([new SettingsChanged([RegistrationRole::SETTING, SignUp::SETTING], (int) $this->admin->id())], $this->events->events);

        $html = (string) $this->handle('GET')->getBody();
        self::assertStringContainsString('Anyone can create an account. New accounts get the “Contributor” role once they confirm their email address.', $html);
        self::assertStringContainsString('value="1" checked name="open"', $html);

        $this->events->events = [];
        $this->handle('POST', ['open' => '1', 'role' => 'contributor']);
        self::assertSame(['Nothing was changed.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame([], $this->events->events);

        $this->handle('POST', ['role' => 'contributor']);
        self::assertSame('0', $this->fixture->auth->database->settings()->get(SignUp::SETTING));
    }

    public function testAMembersOnlySiteCanTurnTheOrganisationsDomainRegistrationOffButNeverOn(): void
    {
        $tenant = ['domain_registration' => ['domains' => ['example.org', 'example.com']]];
        $settings = $this->fixture->auth->database->settings();
        self::assertStringNotContainsString('Registration with an organisation email address', (string) $this->handle('GET', tenant: $tenant)->getBody(), 'a public site');

        $settings->set(\SightPress\Core\Site\Contract\SiteVisibility::SETTING, 'members');
        $html = (string) $this->handle('GET', tenant: $tenant)->getBody();
        self::assertStringContainsString('<legend>Registration with an organisation email address</legend>', $html);
        self::assertStringContainsString('Your organisation lets people with an email address at example.org and example.com create an account on members-only sites', $html);
        self::assertStringContainsString('value="tenant" checked', $html);

        $this->handle('POST', ['role' => 'member', 'domain_registration' => 'off'], tenant: $tenant);
        self::assertSame('off', $settings->get(\SightPress\Core\Auth\Contract\DomainRegistration::SETTING));
        self::assertEquals([new SettingsChanged([\SightPress\Core\Auth\Contract\DomainRegistration::SETTING], (int) $this->admin->id())], $this->events->events);
        self::assertStringContainsString('value="off" checked', (string) $this->handle('GET', tenant: $tenant)->getBody());

        $forged = $this->handle('POST', ['role' => 'member', 'domain_registration' => 'on'], tenant: $tenant);
        self::assertSame(422, $forged->getStatusCode());
        $this->handle('POST', ['role' => 'member', 'domain_registration' => 'tenant'], tenant: $tenant);
        self::assertSame('tenant', $settings->get(\SightPress\Core\Auth\Contract\DomainRegistration::SETTING));

        $this->handle('POST', ['role' => 'member', 'domain_registration' => 'off']);
        self::assertSame('tenant', $settings->get(\SightPress\Core\Auth\Contract\DomainRegistration::SETTING), 'not offered by the tenant: nothing to change');
    }

    public function testAnUnsafeRoleIsRefusedWhateverIsSent(): void
    {
        $response = $this->handle('POST', ['open' => '1', 'role' => 'admin']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Select one of the options for “Role of people who sign up”', (string) $response->getBody());
        self::assertSame('member', $this->fixture->auth->database->registrationRole()->name());
        self::assertNull($this->fixture->auth->database->settings()->get(SignUp::SETTING));
    }

    public function testARefusalFromTheRolesIsShownOnTheField(): void
    {
        $roles = self::createStub(SignUpRoles::class);
        $roles->method('choices')->willReturn(['member' => 'Member', 'editor' => 'Editor']);
        $roles->method('forNewAccount')->willReturn('member');
        $roles->method('name')->willReturn('member');
        $roles->method('change')->willThrowException(new \DomainException('People who sign up can\'t get the "editor" role.'));

        $response = $this->handle('POST', ['open' => '1', 'role' => 'editor'], roles: $roles);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('People who sign up can&#039;t get the &quot;editor&quot; role.', (string) $response->getBody());
        self::assertNull($this->fixture->auth->database->settings()->get(SignUp::SETTING));
    }

    /**
     * @param array<string, string> $body
     * @param array<mixed>          $tenant
     */
    private function handle(string $method, array $body = [], ?User $user = null, ?SignUpRoles $roles = null, array $tenant = []): ResponseInterface
    {
        $auth = $this->fixture->auth;

        return new SignUpSettingsHandler($this->fixture->shell(), $auth->validator(), $auth->database->settings(), $roles ?? $auth->database->registrationRole(), $this->events, $auth->domainRegistration($tenant))
            ->handle($this->fixture->request($user ?? $this->admin, $method, SignUpSettingsHandler::PATH, $body, $this->session));
    }
}
