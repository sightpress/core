<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use SightPress\Core\Template\ViewerPreferences;

/**
 * How a person wants pages shown to them: their time zone, and their language when they chose one. Sign-in puts it
 * on the request so dates and admin text follow it.
 */
interface AccountPreferences
{
    public function preferencesOf(int $userId): ?ViewerPreferences;
}
