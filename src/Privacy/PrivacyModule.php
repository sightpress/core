<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy;

use SightPress\Core\Privacy\Contract\PersonalDataInventory;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * Privacy & data protection (GD-1, D43–D51, ADR-0075): the inventory of personal data every module declares, the
 * third parties the site uses, and the permission to manage them. Required: a site always knows what it keeps.
 */
final class PrivacyModule implements ModuleInterface
{
    public const string ID = 'privacy';

    public const string MANAGE = 'privacy.manage';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        $services->autowire(PersonalDataInventory::class, public: true);
        $services->autowire(MailerProcessors::class);
        $registrar->privacy()->addProcessors(MailerProcessors::class);
        $registrar->permissions()->add(new Permission(
            self::MANAGE,
            'Manage privacy',
            'See what personal data the site keeps and who it is shared with, choose how long it is kept, and answer people’s requests for their data.',
            ['admin'],
        ));
    }
}
