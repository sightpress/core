<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * What a verification code proves. A code only works for the purpose it was made for.
 */
enum VerificationPurpose: string
{
    case EmailVerify = 'email_verify';
    case EmailChange = 'email_change';
    case PasswordReset = 'password_reset';
    case Invitation = 'invitation';
    case AccountSetup = 'account_setup';
    case PhoneVerify = 'phone_verify';
    case StepUp = 'step_up';

    /** 15 minutes: a code sent by email. */
    public const int EMAIL_LIFETIME = 900;

    /** 10 minutes: a code sent by text message. */
    public const int SMS_LIFETIME = 600;

    /** 7 days: an invitation, or the first admin's setup code. */
    public const int LONG_LIFETIME = 604800;

    /**
     * How long a code works, in seconds.
     *
     * @return positive-int
     */
    public function lifetime(VerificationChannel $channel): int
    {
        if ($this === self::Invitation || $this === self::AccountSetup) {
            return self::LONG_LIFETIME;
        }

        return $channel === VerificationChannel::Sms ? self::SMS_LIFETIME : self::EMAIL_LIFETIME;
    }
}
