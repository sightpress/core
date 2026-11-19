<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use SightPress\Core\Infrastructure\Assistant\AssistantAccess;

/**
 * What the assistant panel or a Suggest button sent, checked: the field to suggest (or none, for a question), the
 * question, whether to share the saved page, the page and its language, the screen, the admin path to go back to
 * and the id of the field to fill there (`target`, or `target_prefix` and the field: `en-gb` + `summary`). Anything
 * else is ignored.
 */
final readonly class AssistantAsk
{
    /** Longest question kept; the task refuses longer ones in words. */
    public const int MAX_QUESTION = 4000;

    /**
     * @param non-empty-string|null $screen
     * @param non-empty-string      $return
     */
    public function __construct(
        public ?string $field,
        public string $question,
        public bool $share,
        public ?int $pageId,
        public ?string $locale,
        public ?string $screen,
        public string $return,
        public ?string $target,
    ) {
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $text = static fn (string $name): string => is_string($body[$name] ?? null) ? trim($body[$name]) : '';
        $field = $text('field');
        $page = $text('page');
        $locale = $text('locale');
        $screen = $text('screen');
        $target = $text('target');
        $prefix = $text('target_prefix');
        if ($target === '' && $prefix !== '' && isset(AssistantAccess::FIELD_TASKS[$field])) {
            $target = $prefix . '-' . str_replace('_', '-', $field);
        }

        return new self(
            isset(AssistantAccess::FIELD_TASKS[$field]) ? $field : null,
            mb_substr($text('question'), 0, self::MAX_QUESTION),
            $text('share') === '1',
            preg_match('/^[1-9]\d{0,9}$/', $page) === 1 ? (int) $page : null,
            preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/', $locale) === 1 ? $locale : null,
            in_array($screen, AssistantAskHandler::SCREENS, true) ? $screen : null,
            self::adminPath($text('return')),
            preg_match('/^[a-z][a-z0-9-]{0,80}$/', $target) === 1 ? $target : null,
        );
    }

    /**
     * An admin screen's path (with its query), or the dashboard: never another site.
     *
     * @return non-empty-string
     */
    public static function adminPath(string $path): string
    {
        return preg_match('~^/admin(?:[/?][^\s\\\\#]*)?$~', $path) === 1 && !str_contains($path, '//') ? $path : '/admin';
    }
}
