<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Provisioning;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\LocaleCode;

/**
 * New site, step {@see SiteProvisioning::CONTENT}: writes and publishes the starter pages ({@see StarterContent}) in
 * every language of the site. The first admin is their author and gets the contact form's messages.
 */
final readonly class StarterPages
{
    public function __invoke(SiteProvisioning $event): void
    {
        $report = Services::get($event->services, StarterContent::class)->create(
            trim($event->admin->firstName . ' ' . $event->admin->lastName),
            $event->baseUrl,
            $event->admin->email,
            $event->adminId(),
        );

        $event->report(sprintf(
            'Published the starter pages in %s: %s.',
            implode(', ', array_map(LocaleCode::describe(...), $report->locales)),
            implode(', ', $report->titles),
        ));
        foreach ($report->fallbacks as $locale) {
            $event->report(sprintf('Warning: there are no starter pages in %s yet, so they are in %s. Translate them.', LocaleCode::describe($locale), LocaleCode::describe(StarterContent::LOCALES[0])));
        }
    }
}
