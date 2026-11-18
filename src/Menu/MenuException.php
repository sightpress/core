<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Template\TranslatableText;
use RuntimeException;

/**
 * A menu change that can't be made; the message says why, in plain words for the person who tried.
 */
final class MenuException extends RuntimeException
{
    use TranslatableText;
}
