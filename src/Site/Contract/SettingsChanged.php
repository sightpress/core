<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

/**
 * Someone changed site settings in the admin: which ones, and who. Modules react (the branding makes its generated
 * pictures again for a new name) and the audit log records it.
 */
final readonly class SettingsChanged
{
    /**
     * @param list<string> $keys keys of the settings whose value changed
     */
    public function __construct(
        public array $keys,
        public ?int $actorId,
    ) {
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }
}
