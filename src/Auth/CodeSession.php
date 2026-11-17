<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Http\Session\Session;

/**
 * Where someone is in a code flow, kept in their session: the address a code was sent to (waiting for the code),
 * then the account the code proved (allowed to choose a password, for {@see self::VERIFIED_FOR} seconds).
 */
final class CodeSession
{
    private const string PENDING = 'auth.code.pending';
    private const string VERIFIED = 'auth.code.verified';

    /** How long a proved code lets someone choose a password, in seconds. */
    public const int VERIFIED_FOR = 900;

    public static function startPending(Session $session, VerificationPurpose $purpose, string $email): void
    {
        $session->set(self::PENDING, ['purpose' => $purpose->value, 'email' => $email]);
    }

    /**
     * The address a code for this purpose was sent to, if the person is waiting for one.
     */
    public static function pending(Session $session, VerificationPurpose $purpose): ?string
    {
        $pending = $session->get(self::PENDING);

        return is_array($pending) && ($pending['purpose'] ?? null) === $purpose->value && is_string($pending['email'] ?? null)
            ? $pending['email']
            : null;
    }

    /**
     * The code proved the account: forget the pending address and give the session a new id.
     */
    public static function verified(Session $session, VerificationPurpose $purpose, int $accountId, int $now): void
    {
        $session->remove(self::PENDING);
        $session->set(self::VERIFIED, ['purpose' => $purpose->value, 'account' => $accountId, 'until' => $now + self::VERIFIED_FOR]);
        $session->regenerate();
    }

    /**
     * The proved account, while that still counts.
     */
    public static function verifiedAccount(Session $session, int $now): ?int
    {
        $verified = $session->get(self::VERIFIED);

        return is_array($verified) && is_int($verified['account'] ?? null) && is_int($verified['until'] ?? null) && $verified['until'] >= $now
            ? $verified['account']
            : null;
    }

    public static function clear(Session $session): void
    {
        $session->remove(self::PENDING);
        $session->remove(self::VERIFIED);
    }
}
