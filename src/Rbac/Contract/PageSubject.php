<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * What the page voter needs to know about a page. The page entity implements it.
 */
interface PageSubject
{
    /**
     * Id of the user who created the page, or null when that account is gone.
     */
    public function authorId(): ?int;

    public function state(): PageState;

    /**
     * Whether anonymous visitors may read the page once it is published (per-page visibility `public`); `false`
     * means members only.
     */
    public function isPublic(): bool;
}
