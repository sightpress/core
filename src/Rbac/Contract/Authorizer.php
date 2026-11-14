<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * The one place access is decided.
 */
interface Authorizer
{
    /**
     * Without a subject: whether the actor holds the permission. With one: what the voter for the subject says;
     * a subject no voter supports is refused.
     */
    public function can(Actor $actor, string $action, ?object $subject = null): bool;
}
