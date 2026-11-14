<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use DomainException;

/**
 * A role change breaks an RBAC rule, for example a grant to `member` or an unsafe sign-up role. The message says
 * what to do instead, in plain en-GB.
 */
final class RbacException extends DomainException
{
}
