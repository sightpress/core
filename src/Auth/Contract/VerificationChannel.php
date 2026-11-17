<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * How a verification code reaches the person. Only `email` is used until phone numbers arrive (M4).
 */
enum VerificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
