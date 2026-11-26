<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Content;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\Modules\ExampleBlockType;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\StyledBlockType;
use stdClass;
use UnexpectedValueException;

#[CoversClass(BlockTypeCatalogue::class)]
#[UsesNamespace('SightPress\Core')]
final class BlockTypeCatalogueTest extends TestCase
{
    public function testBuildsTheRegisteredTypesSortedByName(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        $catalogue = BlockTypeCatalogue::fromRegistry($registry, new ContainerBuilder()->addDefinitions([ContactFormBlock::class => new ContactFormBlock(new AuthFixture()->templates)])->build());

        self::assertSame(['audio', 'call_to_action', 'columns', 'contact_form', 'file_download', 'gallery', 'heading', 'image', 'rich_text', 'video'], array_keys($catalogue->all()));
        self::assertInstanceOf(HeadingBlock::class, $catalogue->get('heading'));
        self::assertNull($catalogue->get('missing'));
        self::assertSame('block', $catalogue->moduleOf('heading'));
        self::assertSame('contact', $catalogue->moduleOf('contact_form'));
        self::assertNull($catalogue->moduleOf('missing'));
    }

    public function testRefusesAClassThatIsNotABlockType(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $container = new ContainerBuilder()->addDefinitions([HeadingBlock::class => new stdClass()])->build();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Block type SightPress\Core\Block\Type\HeadingBlock of module "block" could not be built.');

        BlockTypeCatalogue::fromRegistry($registry, $container);
    }

    public function testRefusesDuplicateNames(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Block type "acme.example" is registered by both module "one" and module "two".');

        new BlockTypeCatalogue([['module' => 'one', 'type' => new ExampleBlockType()], ['module' => 'two', 'type' => new ExampleBlockType()]]);
    }

    public function testRefusesBadNames(): void
    {
        $type = self::createStub(\SightPress\Module\Block\BlockType::class);
        $type->method('name')->willReturn('Acme Block');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Block type "Acme Block" of module "acme" needs a name of lower-case words');

        new BlockTypeCatalogue([['module' => 'acme', 'type' => $type]]);
    }

    public function testGivesEachTypeItsLooksWithTheVariantsModulesAdd(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $registry->addBlockVariant('acme', 'image', new BlockVariant('polaroid', 'Polaroid'));
        $registry->addBlockVariant('acme', 'image', new BlockVariant('rounded', 'Also rounded'));
        $registry->addBlockVariant('acme', 'heading', new BlockVariant('shout', 'Shout'));
        $registry->addBlockVariant('acme', 'acme.missing', new BlockVariant('any', 'Any'));

        $catalogue = BlockTypeCatalogue::fromRegistry($registry, new ContainerBuilder()->addDefinitions([ContactFormBlock::class => new ContactFormBlock(new AuthFixture()->templates)])->build());

        $image = $catalogue->stylesOf('image');
        self::assertNotNull($image);
        self::assertSame(['plain', 'rounded', 'bordered', 'polaroid'], array_map(static fn (BlockVariant $variant): string => $variant->name, $image->variants));
        self::assertSame('Rounded corners', $image->variants[1]->label);
        self::assertSame([BlockOption::Width, BlockOption::Spacing], $image->options);
        self::assertSame([], $catalogue->stylesOf('heading')?->variants);
        self::assertNull($catalogue->stylesOf('missing'));
    }

    public function testKeepsAShortFormWhenAVariantIsAdded(): void
    {
        $type = self::createStub(StyledBlockType::class);
        $type->method('name')->willReturn('acme.signup');
        $type->method('styles')->willReturn(new BlockStyles([new BlockVariant('plain', 'Plain')], [BlockOption::FormLayout], shortForm: true));

        $catalogue = new BlockTypeCatalogue(
            [['module' => 'acme', 'type' => $type], ['module' => 'two', 'type' => new ExampleBlockType()]],
            [['module' => 'other', 'type' => 'acme.signup', 'variant' => new BlockVariant('card', 'Card')], ['module' => 'other', 'type' => 'acme.example', 'variant' => new BlockVariant('card', 'Card')]],
        );

        self::assertTrue($catalogue->stylesOf('acme.signup')?->shortForm);
        self::assertCount(2, $catalogue->stylesOf('acme.signup')->variants);
        self::assertNull($catalogue->stylesOf('acme.example'));
    }
}
