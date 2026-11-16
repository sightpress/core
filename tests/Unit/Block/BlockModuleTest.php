<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\BlockModule;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockFinder;
use SightPress\Core\Block\DefaultBlockFinder;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\DefaultBlockRenderer;
use SightPress\Core\Block\DefaultBlockValidator;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\SiteBlockContexts;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

#[CoversClass(BlockModule::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Site')]
#[UsesNamespace('SightPress\Core\User')]
#[UsesNamespace('SightPress\Core\Rbac')]
#[UsesNamespace('SightPress\Core\Auth')]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Media')]
#[UsesNamespace('SightPress\Core\Page')]
#[UsesNamespace('SightPress\Core\Contact')]
#[UsesNamespace('SightPress\Core\Admin')]
#[UsesNamespace('SightPress\Core\Navigation')]
#[UsesNamespace('SightPress\Core\People')]
#[UsesNamespace('SightPress\Core\Menu')]
final class BlockModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTypesAndServices(): void
    {
        $module = new BlockModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame('block', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'media'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        $types = array_values(array_filter($registry->blockTypes(), static fn ($registered): bool => $registered->module === 'block'));
        self::assertCount(9, $types);
        self::assertSame(HeadingBlock::class, $types[0]->item);
        $services = $registry->services();
        self::assertSame(DefaultBlockValidator::class, $services[BlockValidator::class]->item->class);
        self::assertSame(DefaultBlockRenderer::class, $services[BlockRenderer::class]->item->class);
        self::assertSame(SiteBlockContexts::class, $services[BlockContexts::class]->item->class);
        self::assertTrue($services[BlockValidator::class]->item->public);
        self::assertSame(DefaultBlockFinder::class, $services[BlockFinder::class]->item->class);
        self::assertArrayHasKey(RichTextSanitizer::class, $services);
        self::assertArrayHasKey(DefaultBlockRenderer::class, $services);
    }
}
