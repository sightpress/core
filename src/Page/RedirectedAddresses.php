<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\MovedAddresses;

/**
 * Follows a path of this site through the redirects table ({@see PageRedirects}): one hop, as redirects are always
 * collapsed to one.
 */
final readonly class RedirectedAddresses implements MovedAddresses
{
    public function __construct(
        private PageAddresses $addresses,
        private PageRedirects $redirects,
    ) {
    }

    public function follow(string $url): ?string
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }
        $cut = strcspn($url, '?#');
        $path = substr($url, 0, $cut);
        $rest = substr($url, $cut);
        [$locale, $local] = $this->addresses->split($path);
        if ($this->addresses->findIn($locale, $local) !== null) {
            return $url;
        }
        $redirect = $this->redirects->find($locale, rawurldecode($local));
        if ($redirect === null) {
            return $url;
        }
        $target = $redirect->target();
        if ($target === null) {
            return null;
        }
        $translation = $this->addresses->findIn($redirect->locale(), $target);

        return ($translation === null ? $this->addresses->prefixOf($redirect->locale()) . '/' . $target : $this->addresses->url($translation)) . $rest;
    }
}
