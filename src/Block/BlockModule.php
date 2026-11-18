<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\Contract\BlockFinder;
use SightPress\Core\Block\Type\AudioBlock;
use SightPress\Core\Block\Type\CallToActionBlock;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Block\Type\FileDownloadBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Block\Type\ImageBlock;
use SightPress\Core\Block\Type\RichTextBlock;
use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * Content blocks (ADR-0005): core's block types (heading, text, image, call to action, columns, gallery, video, audio, file download), the
 * {@see BlockValidator} that checks blocks against their type's schema before they are stored, and the
 * {@see BlockRenderer} that turns them into HTML, Markdown and schema.org nodes. Other modules add block types
 * through the block type registrar. Always on.
 */
final class BlockModule implements ModuleInterface
{
    public const string ID = 'block';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('media', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        foreach ([
            HeadingBlock::class, RichTextBlock::class, ImageBlock::class, CallToActionBlock::class, ColumnsBlock::class,
            GalleryBlock::class, VideoBlock::class, AudioBlock::class, FileDownloadBlock::class,
        ] as $type) {
            $registrar->blockTypes()->add($type);
        }

        $services = $registrar->services();
        $services->autowire(RichTextSanitizer::class);
        $services->autowire(DefaultBlockRenderer::class);
        $services->autowire(DefaultBlockValidator::class, BlockValidator::class, public: true);
        $services->autowire(DefaultBlockRenderer::class, BlockRenderer::class, public: true);
        $services->autowire(SiteBlockContexts::class, BlockContexts::class, public: true);
        $services->autowire(MediaTexts::class, public: true);
        $services->autowire(DefaultBlockFinder::class, BlockFinder::class, public: true);
    }
}
