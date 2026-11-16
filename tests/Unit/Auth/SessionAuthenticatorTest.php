<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SessionAuthenticator;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Security\GrantedPermissions;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(SessionAuthenticator::class)]
#[UsesNamespace('SightPress\Core')]
final class SessionAuthenticatorTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testVisitorsWithoutASessionStayAnonymous(): void
    {
        $request = $this->auth->authenticator()->authenticate($this->auth->request());
        $response = $this->auth->authenticator()->respond($request, $this->auth->http->createResponse());

        self::assertFalse(Actor::fromRequest($request)->isSignedIn());
        self::assertFalse(GrantedPermissions::fromRequest($request)->has('admin.access'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testTheSignedInUserAndTheirPermissionsGoOnTheRequest(): void
    {
        $user = $this->auth->user();
        $request = $this->auth->request();
        $this->auth->signIn()->start($request, $user);

        $request = $this->auth->authenticator()->authenticate($request);

        $actor = Actor::fromRequest($request);
        self::assertSame($user->id(), $actor->userId);
        self::assertTrue($actor->has('page.publish'));
        self::assertTrue(GrantedPermissions::fromRequest($request)->has('admin.access'));
        self::assertEquals(new \SightPress\Core\Template\ViewerPreferences(null, 'UTC'), $request->getAttribute(\SightPress\Core\Template\ViewerPreferences::class));
    }

    public function testWithoutPreferencesTheDefaultsStay(): void
    {
        $user = $this->auth->user();
        $request = $this->auth->request();
        $this->auth->signIn()->start($request, $user);
        $none = self::createStub(\SightPress\Core\User\Contract\AccountPreferences::class);
        $none->method('preferencesOf')->willReturn(null);
        $authenticator = new \SightPress\Core\Auth\SessionAuthenticator($this->auth->users, $this->auth->database->roles(), $this->auth->rememberTokens(), $this->auth->signIn(), $none);

        $request = $authenticator->authenticate($request);

        self::assertTrue(Actor::fromRequest($request)->isSignedIn());
        self::assertNull($request->getAttribute(\SightPress\Core\Template\ViewerPreferences::class));
    }

    public function testADeactivatedAccountOrAChangedPasswordSignsTheSessionOut(): void
    {
        $user = $this->auth->user();
        $session = new Session();
        $request = $this->auth->request(session: $session);
        $this->auth->signIn()->start($request, $user);
        $session->set('other', 'kept');

        $user->setPasswordHash($this->auth->hasher->hash('changed on another device'), $this->auth->clock->now());
        $request = $this->auth->authenticator()->authenticate($request);

        self::assertFalse(Actor::fromRequest($request)->isSignedIn());
        self::assertSame(['other' => 'kept'], $session->all());

        $this->auth->signIn()->start($request, $user);
        $user->deactivate($this->auth->clock->now());
        self::assertFalse(Actor::fromRequest($this->auth->authenticator()->authenticate($request))->isSignedIn());

        $session->set(SignIn::SESSION_USER, 999);
        self::assertFalse(Actor::fromRequest($this->auth->authenticator()->authenticate($request))->isSignedIn());
    }

    public function testAKeepMeSignedInCookieStartsASessionAndIsRenewed(): void
    {
        $user = $this->auth->user();
        $old = $this->auth->rememberTokens()->issue((int) $user->id(), true);
        $session = new Session();
        $request = $this->auth->request(cookies: [RememberTokens::SECURE_COOKIE => $this->value($old)], session: $session);
        $authenticator = $this->auth->authenticator();

        $request = $authenticator->authenticate($request);
        $response = $authenticator->respond($request, $this->auth->http->createResponse());

        self::assertSame($user->id(), Actor::fromRequest($request)->userId);
        self::assertSame($user->id(), $session->get(SignIn::SESSION_USER));
        $new = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith(RememberTokens::SECURE_COOKIE . '=', $new);
        self::assertNotSame($this->value($old), $this->value($new));
    }

    public function testACookieThatNoLongerWorksIsRemoved(): void
    {
        $user = $this->auth->user(deactivated: true);
        $cookie = $this->value($this->auth->rememberTokens()->issue((int) $user->id(), true));
        $authenticator = $this->auth->authenticator();

        foreach ([$cookie, str_repeat('a', 32) . '.' . str_repeat('B', 43)] as $value) {
            $request = $authenticator->authenticate($this->auth->request(cookies: [RememberTokens::SECURE_COOKIE => $value]));
            $response = $authenticator->respond($request, $this->auth->http->createResponse());

            self::assertFalse(Actor::fromRequest($request)->isSignedIn());
            self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        }
    }

    private function value(string $header): string
    {
        return explode('=', explode(';', $header)[0], 2)[1];
    }
}
