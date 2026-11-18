<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\Template\TranslatableText;
use InvalidArgumentException;

/**
 * A user's details break a rule, for example an empty first name or an unknown time zone. The message says what
 * to change, in plain en-GB.
 */
final class UserException extends InvalidArgumentException
{
    use TranslatableText;
}
