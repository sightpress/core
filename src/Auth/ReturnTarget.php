<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

/**
 * Where to send someone after they sign in: back to the page they came from, if it is a page of this site and
 * they may see it. People without `admin.access` never go to `/admin` (they go to the home page instead); staff
 * without a page to return to go to the admin panel.
 */
final class ReturnTarget
{
    public static function for(Actor $actor, ?string $return): string
    {
        $canAdmin = $actor->has(AdminAccessMiddleware::PERMISSION);
        $path = self::localPath($return);
        if ($path === null) {
            return $canAdmin ? '/admin' : '/';
        }
        if (!$canAdmin && preg_match('#^/admin(?:[/?\#]|$)#', $path) === 1) {
            return '/';
        }

        return $path;
    }

    /**
     * The value when it is a path on this site (`/…`, but not `//host` or a backslash trick), otherwise null. The
     * sign-in pages themselves are never a place to go back to: a password link would leak its token and no longer
     * work.
     */
    public static function localPath(?string $return): ?string
    {
        if (
            $return === null
            || preg_match('#^/(?![/\\\\])[^\\\\\s]*$#', $return) !== 1
            || preg_match('#^/(?:login|logout|password)(?:[/?\#]|$)#', $return) === 1
        ) {
            return null;
        }

        return $return;
    }
}
