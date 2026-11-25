<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SightPress\Core\Template\Message;

/**
 * What a core module checks before a site may turn it on (ADR-0069): text messages, for example, need a provider
 * first. The module registers it in the site container as {@see PREFIX} followed by its id;
 * {@see SiteModules::turnOn()} refuses with its message.
 */
interface ModuleTurnOnCheck
{
    public const string PREFIX = 'module.turn_on_check.';

    /**
     * Why the module can't be turned on yet, or null when it can.
     */
    public function problem(): ?Message;
}
