<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Auth\SignInResult;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(SignIn::class)]
#[CoversClass(SignInResult::class)]
#[UsesNamespace('SightPress\Core')]
final class SignInTest extends TestCase
{
    private AuthFixture $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
    }

    public function testTheRightEmailAndPasswordSignIn(): void
    {
        $user = $this->auth->user();

        $result = $this->auth->signIn()->attempt($this->auth->request('POST'), ' ADA@example.org ', AuthFixture::PASSWORD);

        self::assertTrue($result->succeeded());
        self::assertSame($user, $result->account);
        self::assertNull($result->retryAfter);
    }

    public function testEveryWrongAttemptGetsTheSameAnswer(): void
    {
        $this->auth->user();
        $this->auth->user('pending@example.org', pending: true);
        $this->auth->user('gone@example.org', deactivated: true);
        $signIn = $this->auth->signIn();
        $request = $this->auth->request('POST');

        foreach ([
            ['grace@example.org', AuthFixture::PASSWORD],
            ['ada@example.org', 'wrong password!'],
            ['pending@example.org', AuthFixture::PASSWORD],
            ['gone@example.org', AuthFixture::PASSWORD],
        ] as [$email, $password]) {
            $result = $signIn->attempt($request, $email, $password);
            self::assertFalse($result->succeeded(), $email);
            self::assertNull($result->retryAfter, $email);
        }
    }

    public function testOnlyTheRightPasswordSaysAnAccountWaitsForItsEmailAddressToBeConfirmed(): void
    {
        $this->auth->user(unverified: true);
        $signIn = $this->auth->signIn();
        $request = $this->auth->request('POST');

        $wrong = $signIn->attempt($request, 'ada@example.org', 'wrong password!');
        self::assertNull($wrong->unconfirmedEmail);
        self::assertFalse($wrong->succeeded());

        $right = $signIn->attempt($request, 'ADA@example.org', AuthFixture::PASSWORD);
        self::assertFalse($right->succeeded());
        self::assertNull($right->account);
        self::assertNull($right->retryAfter);
        self::assertSame('ada@example.org', $right->unconfirmedEmail);
    }

    public function testTooManyAttemptsAreRefusedUntilTheWaitIsOver(): void
    {
        $this->auth->user();
        $signIn = $this->auth->signIn();
        $request = $this->auth->request('POST');
        for ($i = 0; $i < 5; ++$i) {
            self::assertNull($signIn->attempt($request, 'ada@example.org', 'wrong password!')->retryAfter);
        }

        $result = $signIn->attempt($request, 'ada@example.org', AuthFixture::PASSWORD);

        self::assertFalse($result->succeeded());
        self::assertNotNull($result->retryAfter);
        self::assertGreaterThan(800, $result->retryAfter);
        self::assertNull(
            $signIn->attempt($request, 'grace@example.org', 'x')->retryAfter,
            'other addresses are not affected',
        );
    }

    public function testSigningInResetsTheCountOfWrongAttempts(): void
    {
        $this->auth->user();
        $signIn = $this->auth->signIn();
        $request = new \Nyholm\Psr7\ServerRequest('POST', 'https://demo.example/login');
        for ($i = 0; $i < 4; ++$i) {
            $signIn->attempt($request, 'ada@example.org', 'wrong password!');
        }
        self::assertTrue($signIn->attempt($request, 'ada@example.org', AuthFixture::PASSWORD)->succeeded());

        for ($i = 0; $i < 5; ++$i) {
            self::assertNull($signIn->attempt($request, 'ada@example.org', 'wrong password!')->retryAfter);
        }
    }

    public function testAnOldHashIsReplacedAtSignIn(): void
    {
        $user = $this->auth->user();
        $old = $user->passwordHash();
        $stronger = new PasswordHasher(['memory_cost' => 16, 'time_cost' => 1, 'threads' => 1]);
        $signIn = new SignIn($this->auth->users, $stronger, $this->auth->limiters, $this->auth->clock, $this->auth->site);

        self::assertTrue($signIn->attempt($this->auth->request('POST'), 'ada@example.org', AuthFixture::PASSWORD)->succeeded());
        self::assertNotSame($old, $user->passwordHash());
        self::assertFalse($stronger->needsRehash((string) $user->passwordHash()));
    }

    public function testASessionIsSignedInForOneSiteWithTheCurrentPassword(): void
    {
        $user = $this->auth->user();
        $session = new Session('old-id', ['kept' => true]);
        $request = $this->auth->request(session: $session);
        $signIn = $this->auth->signIn();

        self::assertNull($signIn->userId($request));
        $signIn->start($request, $user);

        self::assertSame($user->id(), $signIn->userId($request));
        self::assertTrue($signIn->isCurrent($request, $user));
        self::assertSame('old-id', $session->previousId(), 'the session gets a new id');
        self::assertTrue($session->get('kept'));

        $other = new SignIn($this->auth->users, $this->auth->hasher, $this->auth->limiters, $this->auth->clock, SiteFixture::site('other'));
        self::assertNull($other->userId($request), 'a session of one site does not sign in to another');

        $user->setPasswordHash($this->auth->hasher->hash('another long password'), $this->auth->clock->now());
        self::assertFalse($signIn->isCurrent($request, $user));
        self::assertFalse($signIn->isCurrent($this->auth->request(), $user));

        $signIn->end($request);
        self::assertSame([], $session->all());
        self::assertNull($signIn->userId($request));
    }
}
