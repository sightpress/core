<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantPolicies;

/**
 * `email_policy: ['domains' => […]]` (X-5): only addresses at those exact domains may join; the refusal names them.
 */
final readonly class DomainsEmailPolicy implements EmailPolicy
{
    public function __construct(private TenantPolicies $policies)
    {
    }

    public function problem(string $email): ?Message
    {
        return $this->policies->emailProblem($email);
    }
}
