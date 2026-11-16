<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\DefaultBlockFinder;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\VersionedBlockType;

#[CoversClass(DefaultBlockFinder::class)]
#[UsesNamespace('SightPress\Core')]
final class DefaultBlockFinderTest extends TestCase
{
    public function testFindsBlocksOfATypeInReadingOrderAlsoInsideColumns(): void
    {
        $finder = new DefaultBlockFinder(new BlockFixture([new VersionedBlockType()])->types);
        $blocks = [
            ['type' => 'acme.badge', 'version' => 1, 'data' => ['text' => 'First']],
            ['type' => 'acme.gone', 'version' => 1, 'data' => []],
            ['type' => 'acme.badge', 'version' => 9, 'data' => ['label' => 'Future']],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'Second']]]],
                ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'H', 'level' => 2]]]],
            ]]],
            ['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'Third']],
        ];

        self::assertSame([['label' => 'First'], ['label' => 'Second'], ['label' => 'Third']], $finder->find($blocks, 'acme.badge'));
        self::assertSame([], $finder->find($blocks, 'image'));
    }
}
