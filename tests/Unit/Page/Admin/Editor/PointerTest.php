<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Admin\Editor\Pointer;

#[CoversClass(Pointer::class)]
#[UsesNamespace('SightPress\Core')]
final class PointerTest extends TestCase
{
    public function testMakesFormNamesAndIdsThatPublishChecksLinkTo(): void
    {
        self::assertSame('blocks[2][data][alt]', Pointer::name('/blocks/2/data/alt'));
        self::assertSame('blocks', Pointer::name('/blocks'));
        self::assertSame('', Pointer::name(''));
        self::assertSame('en-gb-blocks-2-data-alt', Pointer::id('en-GB', '/blocks/2/data/alt'));
        self::assertSame(['a/b', 'c~d'], Pointer::parts('/a~1b/c~0d'));
    }

    public function testGoesUpALevel(): void
    {
        self::assertSame('/blocks/2', Pointer::parent('/blocks/2/data'));
        self::assertSame('', Pointer::parent('/blocks'));
    }

    public function testReadsAndWritesValues(): void
    {
        $document = ['blocks' => [['type' => 'heading', 'data' => ['text' => 'Hi']]]];

        self::assertSame('Hi', Pointer::get($document, '/blocks/0/data/text'));
        self::assertNull(Pointer::get($document, '/blocks/3'));
        self::assertNull(Pointer::get($document, '/blocks/0/data/text/more'));
        self::assertSame($document, Pointer::get($document, ''));

        $changed = Pointer::set($document, '/blocks/0/data/text', 'Hello');
        self::assertSame('Hello', Pointer::get($changed, '/blocks/0/data/text'));
        self::assertSame(['x' => ['y' => 1]], Pointer::set(['x' => 'scalar'], '/x/y', 1));
        self::assertSame(['new' => true], Pointer::set($document, '', ['new' => true]));
        self::assertSame($document, Pointer::set($document, '', 'not a document'));
    }
}
