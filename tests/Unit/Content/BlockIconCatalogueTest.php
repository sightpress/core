<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Content\BlockIconCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Module\Block\BlockIcon;

#[CoversClass(BlockIconCatalogue::class)]
#[UsesNamespace('SightPress\Core')]
final class BlockIconCatalogueTest extends TestCase
{
    private const string ICONS = __DIR__ . '/../../Fixtures/icons/';

    public function testListsTheRegisteredIconsByName(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $registry->addBlockIcon('acme', new BlockIcon('zebra', 'Zebra', self::ICONS . 'good.svg'));
        $registry->addBlockIcon('acme', new BlockIcon('ant', 'Ant', self::ICONS . 'good.svg'));

        $icons = BlockIconCatalogue::fromRegistry($registry);

        self::assertSame(['ant', 'zebra'], array_keys($icons->all()));
        self::assertSame('Zebra', $icons->get('zebra')?->label);
        self::assertNull($icons->get('missing'));
    }

    public function testFindsTheIconForALinkByItsHost(): void
    {
        $icons = new BlockIconCatalogue([
            new BlockIcon('example', 'Example', self::ICONS . 'good.svg', ['example.org']),
            new BlockIcon('social', 'Social', self::ICONS . 'good.svg', ['social.example.org', 'social.test']),
        ]);

        self::assertSame('example', $icons->forUrl('https://example.org/me')?->name);
        self::assertSame('example', $icons->forUrl('https://www.EXAMPLE.org./me')?->name);
        self::assertSame('social', $icons->forUrl('https://social.example.org/@me')?->name);
        self::assertSame('social', $icons->forUrl('https://eu.social.test')?->name);
        self::assertNull($icons->forUrl('https://notexample.org'));
        self::assertNull($icons->forUrl('/about'));
        self::assertNull($icons->forUrl('https://'));
    }

    public function testDrawsASafeDecorativeSvg(): void
    {
        $icons = new BlockIconCatalogue([
            new BlockIcon('good', 'Good', self::ICONS . 'good.svg'),
            new BlockIcon('filled', 'Filled', self::ICONS . 'filled.svg'),
        ]);

        $svg = $icons->svg('good');
        self::assertIsString($svg);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
        self::assertStringContainsString('focusable="false"', $svg);
        self::assertStringContainsString('fill="currentColor"', $svg);
        foreach (['<script', '<title', 'width=', 'class=', 'role=', 'aria-label=', '<?xml'] as $gone) {
            self::assertStringNotContainsString($gone, $svg);
        }
        self::assertSame($svg, $icons->svg('good'));
        self::assertStringContainsString('fill="none"', (string) $icons->svg('filled'));
    }

    public function testLeavesOutIconsThatCannotBeUsed(): void
    {
        $big = sys_get_temp_dir() . '/sp-icon-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($big, '<svg xmlns="http://www.w3.org/2000/svg"><path d="' . str_repeat('M0 0', 20000) . '"/></svg>');
        try {
            $icons = new BlockIconCatalogue([
                new BlockIcon('missing', 'Missing', self::ICONS . 'missing.svg'),
                new BlockIcon('broken', 'Broken', self::ICONS . 'broken.svg'),
                new BlockIcon('empty', 'Empty', self::ICONS . 'empty.svg'),
                new BlockIcon('html', 'Html', self::ICONS . 'notsvg.svg'),
                new BlockIcon('big', 'Big', $big),
            ]);

            foreach (['missing', 'broken', 'empty', 'html', 'big', 'unknown'] as $name) {
                self::assertNull($icons->svg($name), $name);
            }
        } finally {
            unlink($big);
        }
    }
}
