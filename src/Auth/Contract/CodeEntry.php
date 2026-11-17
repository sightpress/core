<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

use SightPress\Core\Http\Form\Field;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The parts every "enter your code" page shares: the one code field (never six boxes), the wording of how long a
 * code works, and the line every verification message ends with. Pages render the field with the
 * `@auth/code-entry.html.twig` partial and emails include `@auth/email/code.*.twig`.
 */
final class CodeEntry
{
    public const string FIELD = 'code';

    /**
     * Ends every verification message, by email or text (translated with the message).
     */
    public const string NEVER_A_LINK = 'We will never send you a link. Type this code on the page where you asked for it. Never share it with anyone.';

    /**
     * The "6-digit code" field: a text input with a numeric keyboard and `autocomplete="one-time-code"`, which
     * accepts spaces and dashes.
     *
     * @param string $hint where the code was sent, or where to find it
     */
    public static function field(string $hint): Field
    {
        return new Field(
            self::FIELD,
            '6-digit code',
            hint: $hint,
            constraints: [new Regex(
                '/^[\s\x{2010}-\x{2015}-]*(\d[\s\x{2010}-\x{2015}-]*){6}$/u',
                message: 'Enter the 6 digits of the code, like 123 456',
            )],
            autocomplete: 'one-time-code',
            requiredMessage: 'Enter the 6-digit code',
            inputmode: 'numeric',
        );
    }

    /**
     * "15 minutes", "1 hour", "7 days": how long a code works, in words.
     */
    public static function duration(int $seconds): string
    {
        [$amount, $unit] = match (true) {
            $seconds >= 86400 && $seconds % 86400 === 0 => [intdiv($seconds, 86400), 'day'],
            $seconds >= 3600 && $seconds % 3600 === 0 => [intdiv($seconds, 3600), 'hour'],
            default => [max(1, intdiv($seconds, 60)), 'minute'],
        };

        return sprintf('%d %s%s', $amount, $unit, $amount === 1 ? '' : 's');
    }

    /**
     * "Wait 45 seconds" / "Wait 2 minutes": how long until a new code can be sent, in words.
     *
     * @param positive-int $seconds
     */
    public static function wait(int $seconds): string
    {
        if ($seconds <= 60) {
            return sprintf('%d %s', $seconds, $seconds === 1 ? 'second' : 'seconds');
        }
        return sprintf('%d minutes', (int) ceil($seconds / 60));
    }
}
