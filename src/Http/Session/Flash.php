<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

/**
 * Short messages for the next page someone sees, for example "Your password has been changed". Pages show them in
 * a polite live region and they are gone after that.
 */
final class Flash
{
    public const string KEY = 'flash';

    public static function add(Session $session, string $message): void
    {
        $messages = self::peek($session);
        $messages[] = $message;
        $session->set(self::KEY, $messages);
    }

    /**
     * The messages waiting, which are then forgotten.
     *
     * @return list<string>
     */
    public static function take(Session $session): array
    {
        $messages = self::peek($session);
        $session->remove(self::KEY);

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function peek(Session $session): array
    {
        $stored = $session->get(self::KEY);
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, is_string(...)));
    }
}
