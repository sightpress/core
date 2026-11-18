<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use SightPress\Core\Template\Message;

/**
 * Short messages for the next page someone sees, for example "Your password has been changed". Pages show them in
 * a polite live region and they are gone after that.
 */
final class Flash
{
    public const string KEY = 'flash';

    public static function add(Session $session, string|Message $message): void
    {
        $stored = $session->get(self::KEY);
        $stored = is_array($stored) ? $stored : [];
        $stored[] = Message::of($message)->export();
        $session->set(self::KEY, $stored);
    }

    /**
     * The messages waiting, which are then forgotten. Templates show them translated with `t()`.
     *
     * @return list<Message>
     */
    public static function take(Session $session): array
    {
        $stored = $session->get(self::KEY);
        $session->remove(self::KEY);

        return array_values(array_filter(array_map(Message::import(...), is_array($stored) ? $stored : [])));
    }
}
