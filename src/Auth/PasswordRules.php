<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Template\Message;
use SensitiveParameter;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The site's {@see PasswordPolicy} (read from its settings each time, so a change applies at once) and the
 * {@see PasswordHasher}, for other modules.
 */
final readonly class PasswordRules implements Passwords
{
    public function __construct(private PasswordHasher $hasher, private SiteSettings $settings)
    {
    }

    public function policy(): PasswordPolicy
    {
        return PasswordPolicy::fromSettings($this->settings);
    }

    public function problem(#[SensitiveParameter] string $password, string $email = ''): string|Message|null
    {
        return $this->policy()->problem($password, $email);
    }

    public function hint(): Message
    {
        return $this->policy()->hint();
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return $this->hasher->hash($password);
    }
}
