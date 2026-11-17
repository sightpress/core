<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Where a code goes: an email address or a phone number, normalised the same way every time, and masked for the
 * "we sent it to …" hint.
 */
final class VerificationTarget
{
    private const string DOT = '•';

    /**
     * Lower-case email, or an E.164 number without spaces, dashes, dots or brackets.
     */
    public static function normalise(VerificationChannel $channel, string $target): string
    {
        return $channel === VerificationChannel::Email
            ? mb_strtolower(trim($target))
            : (string) preg_replace('/[\s().-]/', '', $target);
    }

    /**
     * `p•••@example.org` for an email address, `•••• •••• 1234` for a phone number.
     */
    public static function mask(VerificationChannel $channel, string $target): string
    {
        $target = self::normalise($channel, $target);
        if ($channel === VerificationChannel::Sms) {
            return str_repeat(self::DOT, 4) . ' ' . str_repeat(self::DOT, 4) . ' ' . substr($target, -4);
        }
        $at = mb_strrpos($target, '@');
        if ($at === false || $at === 0) {
            return str_repeat(self::DOT, 3);
        }

        return mb_substr($target, 0, 1) . str_repeat(self::DOT, 3) . mb_substr($target, $at);
    }
}
