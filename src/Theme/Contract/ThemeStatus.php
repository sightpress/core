<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Contract;

use SightPress\Core\Template\Message;

/**
 * Whether the site is shown in the theme its admins chose, for notices in the admin.
 */
interface ThemeStatus
{
    /**
     * Why the site falls back to the default theme, in words for admins; null when it is shown in its own theme.
     */
    public function problem(): ?Message;
}
