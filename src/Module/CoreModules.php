<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Admin\AdminModule;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Assistant\AssistantModule;
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
use SightPress\Core\Privacy\PrivacyModule;
use SightPress\Core\Rbac\RbacModule;
use SightPress\Core\Search\SearchModule;
use SightPress\Core\Seo\SeoModule;
use SightPress\Core\Site\SiteModule;
use SightPress\Core\Sms\SmsModule;
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
            new ModuleCandidate(new AnalyticsModule(), 'sightpress/core', about: [AnalyticsModule::ID => new ModuleAbout(
                'Analytics',
                'Counts how many people visit the site, what they read, where they come from, what they search for and which addresses are not found, and which search engines and AI crawlers read it. No cookies, no tracking scripts and nothing that identifies a visitor is kept.',
                'Visits are no longer counted and the Analytics screen is removed. The figures already counted are kept, until they are deleted or are older than the site keeps them.',
            )]),
            new ModuleCandidate(new AssistantModule(), 'sightpress/core', about: [AssistantModule::ID => new ModuleAbout(
                'AI assistant',
                'Suggestions from an AI service you choose, with your own key: summaries, descriptions for search engines, alt text, drafts and answers to questions. Off until an admin chooses the service and enters its key. Nothing is sent to the service until someone asks for a suggestion, and every suggestion is checked by a person before it is used.',
                'The assistant panel, the Suggest buttons and the AI assistant screen are removed. The settings, the key and the record of what was used are kept.',
            )]),
            new ModuleCandidate(new SmsModule(), 'sightpress/core', about: [SmsModule::ID => new ModuleAbout(
                'Text messages',
                'Text messages (SMS) through a provider your organisation sets up: people can add a mobile number to their account, get notifications by text if they choose to, and sign in with their number. Numbers are sent to the provider only to deliver messages and to check that they are real mobile numbers.',
                'No text messages are sent, numbers can’t be added and phone sign-in stops. Numbers already on accounts and the record of messages sent are kept.',
            )]),
            new ModuleCandidate(new PrivacyModule(), 'sightpress/core'),
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
