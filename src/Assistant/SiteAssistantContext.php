<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Module\Assistant\AssistantContext;

/**
 * Where a task runs: the language of the answer, what the person may do (no one: everything, for the console) and
 * the admin screen they are on.
 */
final readonly class SiteAssistantContext implements AssistantContext
{
    /**
     * @param non-empty-string      $locale
     * @param non-empty-string|null $screen
     */
    public function __construct(
        private string $locale,
        private ?Actor $actor = null,
        private ?string $screen = null,
    ) {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function can(string $permission): bool
    {
        return $this->actor === null || $this->actor->has($permission);
    }

    public function screen(): ?string
    {
        return $this->screen;
    }
}
