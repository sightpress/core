<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\StorageProvisioning;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/settings/storage`: where the site's files are kept. Never shows a key. Needs `settings.manage`. When the
 * person who runs the server allows it ({@see StorageProvisioning::ADMIN_SWITCH}) and has set a provider's keys, it
 * can also set up cloud storage: choosing a provider shows the plan, and nothing happens until that is confirmed.
 */
final readonly class StorageHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/storage';

    public function __construct(
        private AdminScreens $screens,
        private Site $site,
        private StorageProvisioning $provisioning,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(SettingsHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $providers = $this->providers();
        $plan = null;
        $chosen = null;
        $problem = null;
        if ($request->getMethod() === 'POST') {
            if ($providers === []) {
                throw new HttpForbiddenException($request);
            }
            $body = (array) $request->getParsedBody();
            $chosen = is_string($body['provider'] ?? null) && isset($providers[$body['provider']]) ? $body['provider'] : null;
            if ($chosen === null) {
                $problem = new Message('Choose a provider.');
            } else {
                try {
                    $provisioning = $this->provisioning->request($this->site, $chosen);
                    if (($body['confirm'] ?? null) === '1') {
                        $this->provisioning->provision($chosen, $provisioning);
                        $this->events->dispatch(new SettingsChanged([StorageProvisioning::SETTING], $actor->userId));

                        return $this->screens->redirect($request, self::PATH, 'Cloud storage has been set up. The site keeps using its current storage until the person who runs the server moves it.');
                    }
                    $plan = $this->provisioning->plan($this->site, $chosen, $provisioning);
                } catch (ProvisioningException $exception) {
                    $this->logger->error('Storage provisioning failed: {reason}', ['reason' => $exception->getMessage()]);
                    $problem = new Message('Cloud storage couldn’t be set up. The person who runs the server can see why in the log.');
                }
            }
        }
        $s3 = $this->site->storage->s3;

        return $this->screens->render($request, '@admin/storage.html.twig', [
            'cloud' => $this->site->storage->isCloud(),
            'bucket' => $s3?->bucket,
            'region' => $s3?->region,
            'provider' => $s3 === null ? null : ($s3->endpoint === null ? 'Amazon S3' : (string) parse_url($s3->endpoint, PHP_URL_HOST)),
            'cdn' => $this->site->storage->cdnUrl,
            'settings_path' => SettingsHandler::PATH,
            'action' => self::PATH,
            'providers' => $providers,
            'plan' => $plan,
            'chosen' => $chosen,
            'problem' => $problem,
        ], $problem === null ? 200 : 422);
    }

    /**
     * @return array<string, string> the providers this site's admins may use
     */
    private function providers(): array
    {
        if (!$this->provisioning->allowedInAdmin()) {
            return [];
        }

        return array_filter(['aws' => 'Amazon Web Services', 'cloudflare' => 'Cloudflare'], $this->provisioning->hasCredentials(...), ARRAY_FILTER_USE_KEY);
    }
}
