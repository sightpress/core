<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Command;

use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;

/**
 * `site:sms:disable <slug>`: {@see EnableSmsCommand} the other way round.
 */
final readonly class DisableSmsCommand extends EnableSmsCommand
{
    public function __construct(SiteRepository $sites, SiteContainerFactory $containers, SiteModules $modules)
    {
        parent::__construct($sites, $containers, $modules, false);
    }
}
