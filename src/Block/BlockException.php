<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use LogicException;

/**
 * A block type is defined in a way core can't work with, for example a schema keyword it doesn't support. This is
 * a mistake in the module, not in what an editor wrote.
 */
final class BlockException extends LogicException
{
}
