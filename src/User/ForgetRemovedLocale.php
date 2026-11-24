<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Module\Event\LocaleRemoved;

/**
 * When the site stops being written in a language, the people who chose it get the site's default language again.
 */
final readonly class ForgetRemovedLocale
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(LocaleRemoved $event): void
    {
        $this->users->forgetLocale($event->locale);
    }
}
