<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Contract;

use SightPress\Core\Http\Session\Session;

/**
 * A suggestion on its way into a field, without JavaScript: "Insert" on the assistant's result page keeps it in the
 * session and goes back to the screen, which puts it in the field (unsaved) and takes it out of the session.
 */
final class AssistantInsert
{
    public const string SESSION = 'assistant.insert';

    public static function keep(Session $session, string $path, string $field, string $text): void
    {
        $session->set(self::SESSION, ['path' => $path, 'field' => $field, 'text' => $text]);
    }

    /**
     * The suggestion for the screen at the path, once.
     *
     * @return array{field: string, text: string}|null
     */
    public static function take(Session $session, string $path): ?array
    {
        $kept = $session->get(self::SESSION);
        if (!is_array($kept) || ($kept['path'] ?? null) !== $path || !is_string($kept['field'] ?? null) || !is_string($kept['text'] ?? null)) {
            return null;
        }
        $session->remove(self::SESSION);

        return ['field' => $kept['field'], 'text' => $kept['text']];
    }
}
