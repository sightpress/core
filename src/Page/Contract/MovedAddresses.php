<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Where an address of the site leads now, after pages moved (the redirects table, P-1), for links kept elsewhere
 * such as menu entries.
 */
interface MovedAddresses
{
    /**
     * The address as it is now: the same one when nothing moved, the page's new address when it moved (with the same
     * query and fragment), or null when the page there was deleted. Addresses that aren't a path on this site are
     * returned as they are.
     */
    public function follow(string $url): ?string;
}
