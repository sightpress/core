<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Admin\AdminModule;
use SightPress\Core\Audit\AuditModule;
use SightPress\Core\Auth\AuthModule;
use SightPress\Core\Block\BlockModule;
use SightPress\Core\Branding\BrandingModule;
use SightPress\Core\Contact\ContactModule;
use SightPress\Core\Feature\FeatureModule;
use SightPress\Core\Media\Admin\MediaAdminModule;
use SightPress\Core\Media\MediaModule;
use SightPress\Core\Menu\MenuModule;
use SightPress\Core\Page\Admin\PageAdminModule;
use SightPress\Core\Page\PageModule;
use SightPress\Core\People\PeopleModule;
use SightPress\Core\Rbac\RbacModule;
use SightPress\Core\Search\SearchModule;
use SightPress\Core\Seo\SeoModule;
use SightPress\Core\Site\SiteModule;
use SightPress\Core\Theme\ThemeModule;
use SightPress\Core\User\UserModule;

/**
 * The static list of core's own feature modules (ADR-0009). Each feature (users, roles, pages, blocks, media …)
 * adds its `<Context>Module` here when it is built; they register in dependency order, not list order.
 */
final class CoreModules
{
    /**
     * @return list<ModuleCandidate>
     */
    public static function candidates(): array
    {
        return [
            new ModuleCandidate(new SiteModule(), 'sightpress/core'),
            new ModuleCandidate(new UserModule(), 'sightpress/core'),
            new ModuleCandidate(new RbacModule(), 'sightpress/core'),
            new ModuleCandidate(new AuthModule(), 'sightpress/core'),
            new ModuleCandidate(new ThemeModule(), 'sightpress/core'),
            new ModuleCandidate(new MediaModule(), 'sightpress/core'),
            new ModuleCandidate(new BlockModule(), 'sightpress/core'),
            new ModuleCandidate(new PageModule(), 'sightpress/core'),
            new ModuleCandidate(new ContactModule(), 'sightpress/core'),
            new ModuleCandidate(new AdminModule(), 'sightpress/core'),
            new ModuleCandidate(new MediaAdminModule(), 'sightpress/core'),
            new ModuleCandidate(new PageAdminModule(), 'sightpress/core'),
            new ModuleCandidate(new PeopleModule(), 'sightpress/core'),
            new ModuleCandidate(new MenuModule(), 'sightpress/core'),
            new ModuleCandidate(new BrandingModule(), 'sightpress/core'),
            new ModuleCandidate(new SeoModule(), 'sightpress/core'),
            new ModuleCandidate(new FeatureModule(), 'sightpress/core'),
            new ModuleCandidate(new SearchModule(), 'sightpress/core', about: [SearchModule::ID => new ModuleAbout(
                'Site search',
                'A search box on every page of the site, which finds its published pages in the language being read. Members-only pages are only found by people who are signed in.',
                'The search box and the search page are removed. The search index is kept, and is brought up to date when search is turned on again.',
            )]),
            new ModuleCandidate(new AuditModule(), 'sightpress/core'),
        ];
    }

    /**
     * Folders with the entity classes of core's modules, mapped with attributes in every site's entity manager.
     * The module contract has no way to register entities yet; see ADR-0016.
     *
     * @return list<string>
     */
    public static function entityPaths(): array
    {
        $src = dirname(__DIR__);

        return [$src . '/User/Entity', $src . '/Rbac/Entity', $src . '/Media/Entity', $src . '/Page/Entity', $src . '/Menu/Entity'];
    }
}
