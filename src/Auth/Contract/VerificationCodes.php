<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Six-digit codes that prove someone can read an email address (or, later, a phone number). Every verification
 * message carries one of these codes, typed on a page of the site, and never a link (plan D30).
 *
 * A code works once, for {@see VerificationPurpose::lifetime()}, and dies after {@see self::MAX_ATTEMPTS} wrong
 * tries. Only an HMAC of it is stored. There is one live code per purpose and address: a new one replaces the old.
 */
interface VerificationCodes
{
    public const int MAX_ATTEMPTS = 5;

    /** Seconds before a new code can be asked for the same purpose and address. */
    public const int RESEND_AFTER = 60;

    /**
     * Makes a code without any limit, for codes an admin or operator hands out (invitations, setup codes).
     *
     * @param int|null $accountId the account the code is for, when there is one
     */
    public function issue(VerificationPurpose $purpose, VerificationChannel $channel, string $target, ?int $accountId = null): IssuedCode;

    /**
     * Makes a code a visitor asked for: at most one a minute, the `verification_target` rate limit per
     * address, and `verification_client` per client address. Call it whether or not the address has an account, so
     * the answer and its timing are the same.
     *
     * @throws TooManyCodes
     */
    public function request(
        VerificationPurpose $purpose,
        VerificationChannel $channel,
        string $target,
        ?int $accountId,
        string $client,
    ): IssuedCode;

    /**
     * Checks a code as typed (spaces and dashes are fine). A valid code is used up; a wrong one counts a try.
     * Checks are limited per client address (`verification_check`).
     */
    public function check(VerificationPurpose $purpose, VerificationChannel $channel, string $target, string $code, string $client): CodeCheck;
}
