<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Theme\Preview\PreviewContent;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Module\Block\BlockType;
use SightPress\Theme\Preview\PreviewPage;

/**
 * The preview page always shows every block: a block type core registers without a sample entry in
 * `theme-interface`'s `page.json` fails here (add the sample in a `theme-interface` minor).
 */
#[CoversClass(PreviewContent::class)]
#[UsesNamespace('SightPress\Core')]
final class PreviewPageBlocksTest extends TestCase
{
    public function testEveryCoreBlockTypeHasASampleInItsPlace(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $types = [];
        foreach ($registry->blockTypes() as $registered) {
            $type = new ReflectionClass($registered->item)->newInstanceWithoutConstructor();
            self::assertInstanceOf(BlockType::class, $type);
            $types[] = $type->name();
        }
        $content = PreviewContent::build(
            new PreviewTheme('t', 'T', 'A theme.', '1.0.0', 'https://example.org', '/t'),
            array_fill_keys(PreviewPage::MEDIA, 1),
        );
        $shown = array_values(array_diff(array_map(static fn (array $block): string => is_string($block['type'] ?? null) ? $block['type'] : '', $content->blocks), ['heading']));
        $shown[] = 'heading';

        sort($types);
        $sorted = array_values(array_unique($shown));
        sort($sorted);
        self::assertSame($types, $sorted, 'Every core block type, and nothing else, is on the preview page.');
        self::assertSame([], array_diff($types, PreviewPage::BLOCK_ORDER), 'Every core block type has its place in BLOCK_ORDER.');
    }
}
