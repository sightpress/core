<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/settings/storage`: where the site's files are kept. Read-only: the person who runs the server changes it
 * with the `site:storage:*` commands. Never shows a key. Needs `settings.manage`.
 */
final readonly class StorageHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/storage';

    public function __construct(private AdminScreens $screens, private Site $site)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Actor::fromRequest($request)->has(SettingsHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $s3 = $this->site->storage->s3;

        return $this->screens->render($request, '@admin/storage.html.twig', [
            'cloud' => $this->site->storage->isCloud(),
            'bucket' => $s3?->bucket,
            'region' => $s3?->region,
            'provider' => $s3 === null ? null : ($s3->endpoint === null ? 'Amazon S3' : (string) parse_url($s3->endpoint, PHP_URL_HOST)),
            'cdn' => $this->site->storage->cdnUrl,
            'settings_path' => SettingsHandler::PATH,
        ]);
    }
}
