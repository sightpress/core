<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Http\Session\Session;

#[CoversClass(CodeSession::class)]
#[UsesClass(Session::class)]
final class CodeSessionTest extends TestCase
{
    public function testRemembersTheAddressAndThenTheProvedAccount(): void
    {
        $session = new Session('old-id');
        self::assertNull(CodeSession::pending($session, VerificationPurpose::PasswordReset));

        CodeSession::startPending($session, VerificationPurpose::PasswordReset, 'ada@example.org');
        self::assertSame('ada@example.org', CodeSession::pending($session, VerificationPurpose::PasswordReset));
        self::assertNull(CodeSession::pending($session, VerificationPurpose::AccountSetup), 'only for its purpose');
        self::assertNull(CodeSession::verifiedAccount($session, 1000));

        CodeSession::verified($session, VerificationPurpose::PasswordReset, 7, 1000);
        self::assertNull(CodeSession::pending($session, VerificationPurpose::PasswordReset));
        self::assertSame('old-id', $session->previousId(), 'the session gets a new id');
        self::assertSame(7, CodeSession::verifiedAccount($session, 1000 + CodeSession::VERIFIED_FOR));
        self::assertNull(CodeSession::verifiedAccount($session, 1001 + CodeSession::VERIFIED_FOR), 'only for a while');

        CodeSession::clear($session);
        self::assertNull(CodeSession::verifiedAccount($session, 1000));
    }

    public function testIgnoresAnythingElseInTheSession(): void
    {
        $session = new Session(null, ['auth.code.pending' => 'x', 'auth.code.verified' => ['account' => '7', 'until' => 2000]]);

        self::assertNull(CodeSession::pending($session, VerificationPurpose::PasswordReset));
        self::assertNull(CodeSession::verifiedAccount($session, 1000));
    }
}
