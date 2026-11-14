<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use RuntimeException;

/**
 * A module can't be loaded or registered: an invalid manifest, a missing or incompatible dependency, a loop of
 * requirements, a clash with another module or a module that can't be turned on or off. The message names the
 * modules involved and says what to do.
 */
final class ModuleException extends RuntimeException
{
}
