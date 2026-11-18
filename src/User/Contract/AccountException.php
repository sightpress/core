<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use SightPress\Core\Template\TranslatableText;
use RuntimeException;

/**
 * An account can't be created or changed; the message says why in plain words.
 */
final class AccountException extends RuntimeException
{
    use TranslatableText;
}
