<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Block\BlockType;
use SightPress\Module\Registrar\BlockTypeRegistrar;

/**
 * Records a module's block types.
 */
final readonly class BlockTypeCollector implements BlockTypeRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $blockType): void
    {
        if (!is_subclass_of($blockType, BlockType::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers block type %s, which is not a class that implements %s.',
                $this->module,
                $blockType,
                BlockType::class,
            ));
        }

        $this->registry->addBlockType($this->module, $blockType);
    }
}
