<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Dispatched after someone added a redirect of their own or removed one (redirects the pages make as they move are
 * part of the {@see PageMoved} or {@see PageSaved} change).
 */
final readonly class RedirectChanged
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';

    /**
     * @param string  $change {@see ADDED} or {@see REMOVED}
     * @param string  $from   the old address, without the leading slash and the locale prefix
     * @param ?string $to     where it leads, or null when the page there was deleted for good
     */
    public function __construct(
        public string $change,
        public string $locale,
        public string $from,
        public ?string $to,
        public ?int $actorId,
    ) {
    }
}
