<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use RuntimeException;

/**
 * A theme can't be used: its folder or `theme.json` is missing or not valid. The message says what to fix.
 */
final class ThemeException extends RuntimeException
{
}
