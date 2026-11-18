<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use Psr\Log\LoggerInterface;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IndexNow (indexnow.org): when it is switched on in the site settings (off by default, D12, because it contacts
 * search engines), publishing or unpublishing a page tells the search engines that take part, so they look at
 * it again soon (and so does archiving or trashing a published page). Only in production. The site's key is made when it is first needed and served at {@see PATH}; a
 * failed ping is logged and never stops the page being published.
 */
final readonly class IndexNow
{
    public const string SETTING = 'seo.indexnow';

    public const string KEY_SETTING = 'seo.indexnow_key';

    public const string PATH = '/indexnow-key.txt';

    public const string ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function __construct(
        private SiteSettings $settings,
        private Site $site,
        private PageLinks $pages,
        private HttpClientInterface $http,
        private TenantConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function isOn(): bool
    {
        return $this->settings->get(self::SETTING) === '1';
    }

    /**
     * The site's key: 32 hex characters, made and stored the first time.
     */
    public function key(): string
    {
        $key = $this->settings->get(self::KEY_SETTING);
        if ($key === null || preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
            $key = bin2hex(random_bytes(16));
            $this->settings->set(self::KEY_SETTING, $key);
        }

        return $key;
    }

    public function __invoke(PageTransitioned $event): void
    {
        if (($event->from !== PageState::Published && $event->to !== PageState::Published)
            || !$this->isOn()
            || !$this->config->environment->isProduction()) {
            return;
        }
        $link = $this->pages->find([$event->pageId], $this->site->defaultLocale)[$event->pageId] ?? null;
        $host = $this->site->domains[0] ?? null;
        if ($link === null || $host === null) {
            return;
        }
        try {
            $this->http->request('POST', self::ENDPOINT, [
                'json' => [
                    'host' => $host,
                    'key' => $this->key(),
                    'keyLocation' => 'https://' . $host . self::PATH,
                    'urlList' => ['https://' . $host . $link->url],
                ],
                'timeout' => 3,
            ])->getStatusCode();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('IndexNow could not be told about a page.', ['site' => $this->site->slug, 'page' => $event->pageId, 'error' => $exception->getMessage()]);
        }
    }
}
