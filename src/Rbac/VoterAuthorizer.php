<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\Voter;

/**
 * Decides with the actor's permissions, and with the first voter that supports the subject. Refuses by default.
 */
final readonly class VoterAuthorizer implements Authorizer
{
    /**
     * @param list<Voter> $voters
     */
    public function __construct(private array $voters)
    {
    }

    public function can(Actor $actor, string $action, ?object $subject = null): bool
    {
        if ($subject === null) {
            return $actor->has($action);
        }

        foreach ($this->voters as $voter) {
            if ($voter->supports($action, $subject)) {
                return $voter->vote($actor, $action, $subject);
            }
        }

        return false;
    }
}
