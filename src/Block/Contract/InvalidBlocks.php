<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use InvalidArgumentException;

/**
 * Blocks that can't be saved, with everything that is wrong with them.
 */
final class InvalidBlocks extends InvalidArgumentException
{
    /**
     * @param non-empty-list<BlockViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The blocks can\'t be saved yet. ' . implode(' ', array_map(
            static fn (BlockViolation $violation): string => sprintf('%s: %s', $violation->pointer, $violation->message),
            $violations,
        )));
    }
}
