<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Privacy;

use SightPress\Core\Seo\IndexNow;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * IndexNow, while it is on (it is off by default, and only pings from a production site): it is told the address of
 * a page that was published or taken down, and nothing about people.
 */
final readonly class IndexNowProcessors implements ProcessorSource
{
    public function __construct(
        private IndexNow $indexNow,
        private TenantConfig $config,
    ) {
    }

    public function processors(): iterable
    {
        if (!$this->indexNow->isOn() || !$this->config->environment->isProduction()) {
            return [];
        }

        return [new ProcessorDeclaration(
            'seo.indexnow',
            'IndexNow',
            new Message('The addresses of pages when they are published or taken down, so search engines update them sooner. Nothing about the people who use the site.'),
            ['US'],
            [(string) parse_url(IndexNow::ENDPOINT, PHP_URL_HOST)],
            'https://www.indexnow.org/terms',
        )];
    }
}
