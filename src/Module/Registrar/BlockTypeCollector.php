<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Block\BlockIcon;
use SightPress\Module\Block\BlockType;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\CodeHighlighter;
use SightPress\Module\Block\CodeLanguage;
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

    public function addVariant(string $blockType, BlockVariant $variant): void
    {
        $this->registry->addBlockVariant($this->module, $blockType, $variant);
    }

    public function addIcon(BlockIcon $icon): void
    {
        $this->registry->addBlockIcon($this->module, $icon);
    }

    public function addCodeLanguage(CodeLanguage $language): void
    {
        if ($language->highlighter !== null && !is_subclass_of($language->highlighter, CodeHighlighter::class)) {
            throw new ModuleException(sprintf(
                'Module "%s" registers code language "%s" with highlighter %s, which is not a class that implements %s.',
                $this->module,
                $language->id,
                $language->highlighter,
                CodeHighlighter::class,
            ));
        }

        $this->registry->addCodeLanguage($this->module, $language);
    }
}
