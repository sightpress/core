<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy;

use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Privacy\Command\PurgeCommand;
use SightPress\Core\Privacy\Contract\PersonalDataInventory;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Privacy\Http\PrivacySettingsHandler;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\Routing\Route;
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
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('admin', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        $services->autowire(PersonalDataInventory::class, public: true);
        $services->autowire(RetentionPolicy::class, public: true);
        foreach ([PrivacyPurge::class, PrivacyPurgeJob::class, PrivacySettingsHandler::class] as $class) {
            $services->autowire($class);
        }
        $registrar->subscribers()->listen(ScheduleDue::class, PrivacyPurgeJob::class);
        $registrar->commands()->add(PurgeCommand::class);
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/privacy');
        $registrar->routes()->add(new Route('privacy.settings', PrivacySettingsHandler::PATH, PrivacySettingsHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('privacy.settings', 'Privacy', 'privacy.settings', self::MANAGE, 'admin.settings', 45));
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
