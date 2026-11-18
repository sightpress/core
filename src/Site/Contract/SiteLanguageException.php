<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use SightPress\Core\Template\TranslatableText;
use RuntimeException;

/**
 * A change to the site's languages that can't be made; the message says why, in words for the person making it.
 */
final class SiteLanguageException extends RuntimeException
{
    use TranslatableText;
}
