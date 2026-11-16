<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandColours;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Tenant\Site;

/**
 * `/site.webmanifest`: the web app manifest, so a site added to a phone's home screen has its name, icons and colour.
 */
final readonly class ManifestHandler implements RequestHandlerInterface
{
    public const string PATH = '/site.webmanifest';

    public function __construct(
        private BrandingService $branding,
        private Site $site,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $state = $this->branding->state();
        if ($state->version === '') {
            $state = $this->branding->regenerate();
        }
        $version = '?v=' . rawurlencode($state->version);
        $icons = [
            ['src' => '/icon-192.png' . $version, 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/icon-512.png' . $version, 'sizes' => '512x512', 'type' => 'image/png'],
        ];
        if (!$state->isUploaded(BrandAsset::Icon)) {
            $icons[] = ['src' => '/icon.svg' . $version, 'sizes' => 'any', 'type' => 'image/svg+xml'];
        }
        $name = $this->site->name;
        $manifest = [
            'name' => $name,
            'short_name' => mb_strlen($name) <= 12 ? $name : rtrim(mb_substr($name, 0, 11)) . '…',
            'lang' => $this->site->defaultLocale,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            'theme_color' => BrandColours::BACKGROUND,
            'background_color' => BrandColours::BACKGROUND,
            'icons' => $icons,
        ];
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', 'application/manifest+json')
            ->withHeader('Cache-Control', 'public, max-age=3600');
        $response->getBody()->write(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response;
    }
}
