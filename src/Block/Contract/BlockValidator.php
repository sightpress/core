<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

/**
 * Checks a page's blocks before they are stored (ADR-0005): known type, data that fits the type's schema, files
 * that exist; upgrades old data, sanitises rich text and runs the types' accessibility rules.
 */
interface BlockValidator
{
    /**
     * @param list<mixed> $blocks blocks shaped `{type, version, data}`
     *
     * @throws InvalidBlocks when any block can't be saved
     */
    public function validate(array $blocks): ValidatedBlocks;
}
