<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use SightPress\Core\Http\Session\Session;

/**
 * What two-step confirmation keeps in the session (N-6): when the person last typed a code ({@see WINDOW} seconds count,
 * bound to this session's id, which signing in replaces), and the form they were sending, kept server-side without
 * passwords or files until they have confirmed.
 */
final class StepUpSession
{
    /** D34: a confirmation lasts 10 minutes. */
    public const int WINDOW = 600;

    private const string CONFIRMED = 'auth.step_up.confirmed';

    private const string HELD = 'auth.step_up.held';

    /** Fields never kept: whatever looks like a password or a token. */
    private const string SECRET = '/pass(word)?|secret|token|key|csrf_|code/i';

    public static function isConfirmed(Session $session, int $now): bool
    {
        $confirmed = $session->get(self::CONFIRMED);
        $id = $session->id();

        return is_array($confirmed)
            && is_int($confirmed['at'] ?? null)
            && $now - $confirmed['at'] <= self::WINDOW
            && $id !== null
            && is_string($confirmed['session'] ?? null)
            && hash_equals($confirmed['session'], self::bind($id));
    }

    public static function confirm(Session $session, int $now): void
    {
        $session->set(self::CONFIRMED, ['at' => $now, 'session' => self::bind($session->idOrNew())]);
    }

    /**
     * Keeps the form being sent. Nested values (lists of checkboxes) are kept one level deep.
     *
     * @param array<mixed> $body
     *
     * @return bool whether a password, a code or a file had to be left out
     */
    public static function hold(Session $session, string $action, string $back, array $body, bool $files): bool
    {
        $kept = [];
        $left = $files;
        foreach ($body as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (preg_match(self::SECRET, $name) === 1) {
                $left = $left || !str_starts_with($name, 'csrf_');
                continue;
            }
            if (is_string($value)) {
                $kept[$name] = mb_substr($value, 0, 20000);
            } elseif (is_array($value)) {
                $kept[$name] = array_values(array_filter($value, is_string(...)));
            }
        }
        $session->set(self::HELD, ['action' => $action, 'back' => $back, 'fields' => $kept, 'left_out' => $left]);

        return $left;
    }

    /**
     * @return array{action: string, back: string, fields: array<string, string|list<string>>, left_out: bool}|null
     */
    public static function held(Session $session): ?array
    {
        $held = $session->get(self::HELD);
        if (!is_array($held) || !is_string($held['action'] ?? null) || !is_string($held['back'] ?? null) || !is_array($held['fields'] ?? null)) {
            return null;
        }
        /** @var array<string, string|list<string>> $fields */
        $fields = $held['fields'];

        return ['action' => $held['action'], 'back' => $held['back'], 'fields' => $fields, 'left_out' => ($held['left_out'] ?? false) === true];
    }

    public static function release(Session $session): void
    {
        $session->remove(self::HELD);
    }

    private static function bind(string $id): string
    {
        return hash('sha256', 'sightpress step-up ' . $id);
    }
}
