<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use SightPress\Core\Infrastructure\Assistant\AssistantAccess;

/**
 * What the assistant panel or a Suggest button sent, checked: the field to suggest (`summary`, `seo_title`,
 * `description`, or `alt_<n>` for the alt text in the site's n-th language), or else the panel's task (`ask`,
 * `rewrite`, `draft_page`, `explain_check`) with its question, text and style, brief or check; whether to share the
 * saved page; the page and its language, or the media file; the screen; the admin path to go back to and the id of
 * the field to fill there (`target`, or `target_prefix` and the field: `en-gb` + `summary`). Anything else is
 * ignored.
 */
final readonly class AssistantAsk
{
    /** Longest question, brief or text kept; the tasks refuse longer ones in words. */
    public const int MAX_QUESTION = 4000;

    public const int MAX_TEXT = 10000;

    /** The tasks the panel's forms ask for (the Suggest buttons name a field instead). */
    public const array TASKS = ['ask', 'rewrite', 'draft_page', 'explain_check'];

    /**
     * @param non-empty-string|null $screen
     * @param non-empty-string      $return
     * @param non-empty-string      $task
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
        public string $task = 'ask',
        public string $text = '',
        public string $style = '',
        public ?int $mediaId = null,
        public ?int $altIndex = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $text = static fn (string $name, int $max = self::MAX_QUESTION): string => is_string($body[$name] ?? null) ? trim(mb_substr($body[$name], 0, $max)) : '';
        $id = static fn (string $name): ?int => preg_match('/^[1-9]\d{0,9}$/', $text($name)) === 1 ? (int) $text($name) : null;
        $field = $text('field');
        $altIndex = null;
        if (preg_match('/^alt_(\d{1,2})$/', $field, $alt) === 1) {
            $field = 'alt';
            $altIndex = (int) $alt[1];
        }
        $field = isset(AssistantAccess::FIELD_TASKS[$field]) && ($field !== 'alt' || $altIndex !== null) ? $field : null;
        $locale = $text('locale');
        $screen = $text('screen');
        $target = $text('target');
        $prefix = $text('target_prefix');
        if ($target === '' && $field === 'alt') {
            $target = 'details-alt-' . $altIndex;
        } elseif ($target === '' && $prefix !== '' && $field !== null) {
            $target = $prefix . '-' . str_replace('_', '-', $field);
        }
        $task = $text('task');

        return new self(
            $field,
            $text('question'),
            $text('share') === '1',
            $id('page'),
            preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/', $locale) === 1 ? $locale : null,
            in_array($screen, AssistantAskHandler::SCREENS, true) ? $screen : null,
            self::adminPath($text('return')),
            preg_match('/^[a-z][a-z0-9-]{0,80}$/', $target) === 1 ? $target : null,
            in_array($task, self::TASKS, true) ? $task : 'ask',
            $text('text', self::MAX_TEXT),
            $text('style'),
            $id('media'),
            $altIndex,
        );
    }

    /**
     * The field as the screen names it (`alt_1` for the alt text in the second language).
     */
    public function fieldName(): ?string
    {
        return $this->field === 'alt' ? 'alt_' . $this->altIndex : $this->field;
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
