<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(VerificationCodeStore::class)]
#[UsesNamespace('SightPress\Core')]
final class VerificationCodeStoreTest extends TestCase
{
    private const string CLIENT = '192.0.2.10';

    private AuthFixture $auth;

    private VerificationCodeStore $codes;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->codes = $this->auth->codes();
    }

    public function testACodeIsSixDigitsAndOnlyItsHashIsStored(): void
    {
        $seen = [];
        for ($i = 0; $i < 20; ++$i) {
            $code = $this->codes->issue(VerificationPurpose::EmailVerify, VerificationChannel::Email, "p{$i}@example.org");
            self::assertMatchesRegularExpression('/^\d{6}$/', $code->code);
            $seen[$code->code] = true;
        }
        self::assertGreaterThan(1, count($seen), 'codes are random');

        $code = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, ' Ada@Example.org ', 7);
        $rows = $this->rows();
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['purpose'] === 'password_reset'))[0];
        self::assertSame('email', $row['channel']);
        self::assertEquals(7, $row['account_id']);
        self::assertEquals(0, $row['attempts']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', self::str($row['code_hash']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', self::str($row['target_hash']));
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($code->code, $dump, 'the code is never stored');
        self::assertStringNotContainsString('ada@example.org', $dump, 'nor the address');
    }

    public function testTheHashIsKeyedFromTheSecret(): void
    {
        $code = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org');
        $other = new VerificationCodeStore($this->auth->database->connection, $this->auth->clock, $this->auth->limiters, 'another secret entirely, also long');

        self::assertSame(CodeStatus::Missing, $other->check(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', $code->code, self::CLIENT)->status);
        self::assertTrue($this->check($code->code)->isValid());
    }

    public function testEachPurposeHasItsLifetime(): void
    {
        $email = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org');
        $sms = $this->codes->issue(VerificationPurpose::PhoneVerify, VerificationChannel::Sms, '+44 7700 900123');
        $invitation = $this->codes->issue(VerificationPurpose::Invitation, VerificationChannel::Email, 'zoe@example.org');
        $now = $this->auth->clock->now()->getTimestamp();

        self::assertSame($now + 900, $email->expiresAt->getTimestamp());
        self::assertSame($now + 600, $sms->expiresAt->getTimestamp());
        self::assertSame($now + 604800, $invitation->expiresAt->getTimestamp());

        $this->auth->clock->advance(900);
        self::assertSame(CodeStatus::Expired, $this->check($email->code)->status);
        self::assertSame(CodeStatus::Expired, $this->codes->check(VerificationPurpose::PhoneVerify, VerificationChannel::Sms, '+447700900123', $sms->code, self::CLIENT)->status);
        self::assertTrue($this->codes->check(VerificationPurpose::Invitation, VerificationChannel::Email, 'zoe@example.org', $invitation->code, self::CLIENT)->isValid());
    }

    public function testACodeWorksOnceForItsPurposeAndAddressAndAcceptsSpacesAndDashes(): void
    {
        $code = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org', 7);
        $typed = substr($code->code, 0, 3) . ' - ' . substr($code->code, 3);

        self::assertSame(CodeStatus::Missing, $this->codes->check(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'ada@example.org', $code->code, self::CLIENT)->status);
        self::assertSame(CodeStatus::Missing, $this->check($code->code, 'max@example.org')->status);
        $valid = $this->check($typed, 'ADA@example.org');
        self::assertSame(CodeStatus::Valid, $valid->status);
        self::assertSame(7, $valid->accountId);
        self::assertSame(CodeStatus::Missing, $this->check($code->code)->status, 'used up');
    }

    public function testACodeWithoutAnAccountIsValidWithoutOne(): void
    {
        $code = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'nobody@example.org');

        $check = $this->check($code->code, 'nobody@example.org');

        self::assertTrue($check->isValid());
        self::assertNull($check->accountId);
    }

    public function testFiveWrongTriesKillTheCode(): void
    {
        $code = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org');
        $wrong = $code->code === '000000' ? '111111' : '000000';

        for ($i = 1; $i < 5; ++$i) {
            self::assertSame(CodeStatus::Wrong, $this->check($wrong)->status, "try {$i}");
        }
        self::assertSame(CodeStatus::Locked, $this->check('12 34 5')->status, 'the fifth wrong try, even malformed');
        self::assertSame(CodeStatus::Locked, $this->check($code->code)->status, 'even the right code is dead');
    }

    public function testANewCodeReplacesTheOldOneAndOldDeadRowsGo(): void
    {
        $first = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org');
        $this->codes->issue(VerificationPurpose::EmailVerify, VerificationChannel::Email, 'old@example.org');
        $this->auth->clock->advance(86400 + 901);
        $second = $this->codes->issue(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'ada@example.org');

        self::assertCount(1, $this->rows(), 'the replaced code and the long-dead one are gone');
        if ($first->code !== $second->code) {
            self::assertSame(CodeStatus::Wrong, $this->check($first->code)->status);
        }
        self::assertTrue($this->check($second->code)->isValid());
    }

    public function testAVisitorWaitsAMinuteBetweenCodes(): void
    {
        $this->request('ada@example.org');
        try {
            $this->request('ada@example.org');
            self::fail('A second code was made within a minute.');
        } catch (TooManyCodes $exception) {
            self::assertTrue($exception->tooSoon);
            self::assertSame(60, $exception->retryAfter);
        }

        $this->auth->clock->advance(45);
        try {
            $this->request('ada@example.org');
            self::fail('A second code was made within a minute.');
        } catch (TooManyCodes $exception) {
            self::assertSame(15, $exception->retryAfter);
        }

        $this->auth->clock->advance(15);
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->request('ada@example.org')->code);
    }

    public function testAfterACodeWasUsedANewOneNeedsNoWait(): void
    {
        $used = $this->request('ada@example.org');
        self::assertSame(CodeStatus::Valid, $this->check($used->code)->status);

        // Another change to confirm right away: the minute only holds while the last code still works.
        $next = $this->request('ada@example.org');
        self::assertSame(CodeStatus::Valid, $this->check($next->code)->status);
    }

    public function testAtMostFiveCodesAnHourPerAddress(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->request('ada@example.org');
            $this->auth->clock->advance(61);
        }

        try {
            $this->request('ada@example.org');
            self::fail('A sixth code was made.');
        } catch (TooManyCodes $exception) {
            self::assertFalse($exception->tooSoon);
            self::assertGreaterThan(60, $exception->retryAfter);
        }
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->request('max@example.org')->code, 'other addresses still get codes');
    }

    public function testAtMostTwentyCodesAnHourPerClient(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->request("p{$i}@example.org");
        }

        $this->expectException(TooManyCodes::class);
        $this->request('p20@example.org');
    }

    public function testAtMostTenTriesPerClientInAQuarterOfAnHour(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->check('123456', "p{$i}@example.org");
        }

        $limited = $this->check('123456', 'p10@example.org');

        self::assertSame(CodeStatus::Limited, $limited->status);
        self::assertGreaterThan(0, $limited->retryAfter);
        self::assertFalse($limited->isValid());
        self::assertSame(CodeStatus::Missing, $this->codes->check(VerificationPurpose::PasswordReset, VerificationChannel::Email, 'p10@example.org', '123456', '192.0.2.99')->status);
    }

    public function testReadsTheDigitsOfATypedCode(): void
    {
        self::assertSame('042917', VerificationCodeStore::digits(' 042 917 '));
        self::assertSame('042917', VerificationCodeStore::digits('042-917'));
        self::assertSame('042917', VerificationCodeStore::digits("042\u{2013}917"));
        self::assertNull(VerificationCodeStore::digits('04291'));
        self::assertNull(VerificationCodeStore::digits('O42917'));
    }

    private function request(string $email): \SightPress\Core\Auth\Contract\IssuedCode
    {
        return $this->codes->request(VerificationPurpose::PasswordReset, VerificationChannel::Email, $email, null, self::CLIENT);
    }

    private function check(string $code, string $email = 'ada@example.org'): \SightPress\Core\Auth\Contract\CodeCheck
    {
        return $this->codes->check(VerificationPurpose::PasswordReset, VerificationChannel::Email, $email, $code, self::CLIENT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->auth->database->connection->createQueryBuilder()->select('*')->from('verification_code')->executeQuery()->fetchAllAssociative();
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
