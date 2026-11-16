<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Finds pages everyone may read (published and public), for modules that act on a public page, such as the contact
 * form.
 */
interface PublicPages
{
    /**
     * @param string $url path on the site, for example `/about/team` or `/pt-br/sobre/equipa`; a query or fragment
     *                    is ignored
     */
    public function findByUrl(string $url): ?PublicPage;
}
