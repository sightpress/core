<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use RuntimeException;

/**
 * The session store can't be used, for example because its server can't be reached. The message says what to check
 * and never holds a session id or a password.
 */
final class SessionStoreException extends RuntimeException
{
}
