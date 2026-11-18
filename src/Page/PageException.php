<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Template\TranslatableText;
use RuntimeException;

/**
 * A page can't be created, saved or moved as asked. The message is a plain en-GB sentence that says how to fix it.
 */
final class PageException extends RuntimeException
{
    use TranslatableText;
}
