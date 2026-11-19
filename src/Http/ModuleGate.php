<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteContext;
use Slim\Exception\HttpNotFoundException;

/**
 * Routes of a module that is off for the request's site don't exist there: they answer 404, as if the module wasn't
 * installed (ADR-0034). Without a site, every route the tenant loaded answers.
 */
final class ModuleGate
{
    /**
     * Whether the module is on for the request's site (always true without a site or a module).
     */
    public static function isOn(ServerRequestInterface $request, ?string $module): bool
    {
        $site = SiteContext::fromRequest($request);
        if ($site === null || $module === null) {
            return true;
        }

        return Services::get($site->container, ModuleRegistry::class)->has($module);
    }

    /**
     * @throws HttpNotFoundException when the module is off for the request's site
     */
    public static function check(ServerRequestInterface $request, ?string $module): void
    {
        if (!self::isOn($request, $module)) {
            throw new HttpNotFoundException($request);
        }
    }
}
