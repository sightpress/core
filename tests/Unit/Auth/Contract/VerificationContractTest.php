<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\CodeCheck;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use Symfony\Component\Validator\Validation;

#[CoversClass(VerificationPurpose::class)]
#[CoversClass(VerificationTarget::class)]
#[CoversClass(IssuedCode::class)]
#[CoversClass(CodeCheck::class)]
#[CoversClass(TooManyCodes::class)]
#[CoversClass(CodeEntry::class)]
#[UsesNamespace('SightPress\Core')]
final class VerificationContractTest extends TestCase
{
    public function testLifetimes(): void
    {
        self::assertSame(900, VerificationPurpose::PasswordReset->lifetime(VerificationChannel::Email));
        self::assertSame(600, VerificationPurpose::PhoneVerify->lifetime(VerificationChannel::Sms));
        self::assertSame(600, VerificationPurpose::StepUp->lifetime(VerificationChannel::Sms));
        self::assertSame(604800, VerificationPurpose::Invitation->lifetime(VerificationChannel::Email));
        self::assertSame(604800, VerificationPurpose::AccountSetup->lifetime(VerificationChannel::Sms));
        self::assertSame(
            ['email_verify', 'email_change', 'password_reset', 'invitation', 'account_setup', 'phone_verify', 'step_up'],
            array_map(static fn (VerificationPurpose $p): string => $p->value, VerificationPurpose::cases()),
        );
    }

    public function testNormalisesAndMasksAddresses(): void
    {
        self::assertSame('ada@example.org', VerificationTarget::normalise(VerificationChannel::Email, ' Ada@Example.ORG '));
        self::assertSame('+447700900123', VerificationTarget::normalise(VerificationChannel::Sms, '+44 (7700) 900-123'));
        self::assertSame('a•••@example.org', VerificationTarget::mask(VerificationChannel::Email, 'Ada@example.org'));
        self::assertSame('ö•••@example.org', VerificationTarget::mask(VerificationChannel::Email, 'ödön@example.org'));
        self::assertSame('•••', VerificationTarget::mask(VerificationChannel::Email, '@example.org'));
        self::assertSame('•••', VerificationTarget::mask(VerificationChannel::Email, 'nobody'));
        self::assertSame('•••• •••• 0123', VerificationTarget::mask(VerificationChannel::Sms, '+44 7700 900123'));
    }

    public function testValueTypes(): void
    {
        $code = new IssuedCode('042917', new DateTimeImmutable('2026-11-17 12:00 UTC'));
        self::assertSame('042 917', $code->readable());

        self::assertTrue(new CodeCheck(CodeStatus::Valid, 7)->isValid());
        self::assertFalse(new CodeCheck(CodeStatus::Wrong)->isValid());

        $limit = new TooManyCodes(42, tooSoon: true);
        self::assertSame(42, $limit->retryAfter);
        self::assertTrue($limit->tooSoon);
        self::assertSame('Too many verification codes: wait 42 seconds.', $limit->getMessage());
        self::assertFalse(new TooManyCodes(1)->tooSoon);
    }

    public function testTheCodeField(): void
    {
        $field = CodeEntry::field('We sent it to a•••@example.org.');
        self::assertSame('code', $field->name);
        self::assertSame('6-digit code', $field->label);
        self::assertSame(FieldType::Text, $field->type);
        self::assertSame('one-time-code', $field->autocomplete);
        self::assertSame('numeric', $field->inputmode);
        self::assertSame('We sent it to a•••@example.org.', $field->hint);

        $validator = new FormValidator(Validation::createValidator());
        $form = Form::create('code', $field);
        foreach (['123456', ' 123 456 ', '123-456', '1 2 3 4 5 6', "123\u{2013}456"] as $typed) {
            self::assertTrue($validator->submit($form, ['code' => $typed])->isValid(), $typed);
        }
        foreach (['12345', '1234567', 'abcdef'] as $typed) {
            self::assertSame('Enter the 6 digits of the code, like 123 456', $validator->submit($form, ['code' => $typed])->error('code'), $typed);
        }
        self::assertSame('Enter the 6-digit code', $validator->submit($form, ['code' => ' '])->error('code'));
    }

    public function testDescribesTimesInWords(): void
    {
        self::assertSame('15 minutes', CodeEntry::duration(900));
        self::assertSame('10 minutes', CodeEntry::duration(600));
        self::assertSame('1 minute', CodeEntry::duration(30));
        self::assertSame('1 hour', CodeEntry::duration(3600));
        self::assertSame('2 hours', CodeEntry::duration(7200));
        self::assertSame('90 minutes', CodeEntry::duration(5400));
        self::assertSame('1 day', CodeEntry::duration(86400));
        self::assertSame('7 days', CodeEntry::duration(604800));

        self::assertSame('1 second', CodeEntry::wait(1));
        self::assertSame('45 seconds', CodeEntry::wait(45));
        self::assertSame('60 seconds', CodeEntry::wait(60));
        self::assertSame('2 minutes', CodeEntry::wait(61));
        self::assertSame('5 minutes', CodeEntry::wait(300));
        self::assertStringContainsString('We will never send you a link.', CodeEntry::NEVER_A_LINK);
    }
}
