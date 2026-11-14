<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * Decides whether an actor may do something to one particular thing, for example edit this page. Voters add the
 * rules permissions alone can't express: ownership, status, visibility.
 */
interface Voter
{
    public function supports(string $action, object $subject): bool;

    public function vote(Actor $actor, string $action, object $subject): bool;
}
