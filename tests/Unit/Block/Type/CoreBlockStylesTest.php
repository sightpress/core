<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SightPress\Core\Block\Type\AudioBlock;
use SightPress\Core\Block\Type\CallToActionBlock;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Block\Type\FileDownloadBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Block\Type\ImageBlock;
use SightPress\Core\Block\Type\RichTextBlock;
use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\StyledBlockType;

#[CoversClass(AudioBlock::class)]
#[CoversClass(CallToActionBlock::class)]
#[CoversClass(ColumnsBlock::class)]
#[CoversClass(FileDownloadBlock::class)]
#[CoversClass(GalleryBlock::class)]
#[CoversClass(HeadingBlock::class)]
#[CoversClass(ImageBlock::class)]
#[CoversClass(RichTextBlock::class)]
#[CoversClass(VideoBlock::class)]
#[CoversClass(ContactFormBlock::class)]
final class CoreBlockStylesTest extends TestCase
{
    public function testEveryCoreTypeOffersTheLooksThatSuitIt(): void
    {
        $offered = [];
        foreach ([
            new HeadingBlock(), new RichTextBlock(), new ImageBlock(), new CallToActionBlock(), new ColumnsBlock(),
            new GalleryBlock(), new VideoBlock(), new AudioBlock(), new FileDownloadBlock(),
            new ReflectionClass(ContactFormBlock::class)->newInstanceWithoutConstructor(),
        ] as $type) {
            self::assertInstanceOf(StyledBlockType::class, $type);
            $styles = $type->styles();
            $offered[$type->name()] = [
                array_map(static fn (BlockVariant $variant): string => $variant->name, $styles->variants),
                array_map(static fn (BlockOption $option): string => $option->value, $styles->options),
            ];
            self::assertFalse($styles->shortForm);
        }

        self::assertSame([
            'heading' => [[], ['align', 'spacing']],
            'rich_text' => [[], ['align', 'background', 'width', 'spacing']],
            'image' => [['plain', 'rounded', 'bordered'], ['width', 'spacing']],
            'call_to_action' => [[], ['align', 'spacing']],
            'columns' => [[], ['background', 'width', 'spacing']],
            'gallery' => [[], ['width', 'spacing']],
            'video' => [[], ['width', 'spacing']],
            'audio' => [[], ['spacing']],
            'file_download' => [[], ['spacing']],
            'contact_form' => [['plain', 'card'], ['background', 'spacing', 'form_layout', 'form_size', 'button_align']],
        ], $offered);
    }
}
