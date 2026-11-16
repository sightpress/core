<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Media\Http\RangeStream;

#[CoversClass(RangeStream::class)]
final class RangeStreamTest extends TestCase
{
    public function testReadsOnlyThePartAskedFor(): void
    {
        $stream = new RangeStream(self::file('0123456789'), 2, 5);

        self::assertSame(5, $stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isSeekable());
        self::assertFalse($stream->isWritable());
        self::assertSame('23', $stream->read(2));
        self::assertSame(2, $stream->tell());
        self::assertFalse($stream->eof());
        self::assertSame('456', $stream->read(100));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));
        self::assertSame('', (string) $stream, 'nothing left');
    }

    public function testCastsTheWholePartToAString(): void
    {
        $stream = new RangeStream(self::file(str_repeat('a', 70000) . 'bcd'), 69999, 3);

        self::assertSame('abc', (string) $stream);
        self::assertSame('', $stream->read(0));
    }

    public function testStopsWhenTheFileEndsEarly(): void
    {
        $stream = new RangeStream(self::file('0123'), 2, 10);

        self::assertSame('23', $stream->getContents());
        self::assertTrue($stream->eof());
    }

    public function testCanBeClosedAndDetached(): void
    {
        $stream = new RangeStream(self::file('0123'), 0, 4);
        self::assertSame('php://memory', $stream->getMetadata('uri'));
        $metadata = $stream->getMetadata();
        self::assertIsArray($metadata);
        self::assertArrayHasKey('mode', $metadata);
        self::assertNull($stream->getMetadata('nothing'));

        $resource = $stream->detach();
        self::assertIsResource($resource);
        self::assertNull($stream->detach());
        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(4));
        self::assertSame([], $stream->getMetadata());
        $stream->close();
        fclose($resource);

        $closed = new RangeStream(self::file('0123'), 0, 4);
        $closed->close();
        $closed->close();
        self::assertFalse($closed->isReadable());
    }

    public function testCannotSeekOrWrite(): void
    {
        $stream = new RangeStream(self::file('0123'), 0, 4);

        foreach ([static fn () => $stream->seek(1), static fn () => $stream->rewind(), static fn () => $stream->write('x')] as $call) {
            try {
                $call();
                self::fail('Expected an exception.');
            } catch (RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testAFileThatCannotBePositionedIsClosed(): void
    {
        $resource = fopen('php://output', 'w');
        self::assertIsResource($resource);

        try {
            new RangeStream($resource, 5, 1);
            self::fail('Expected an exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('The file can\'t be read from the requested position.', $exception->getMessage());
        }

        self::assertSame('resource (closed)', get_debug_type($resource));
    }

    /**
     * @return resource
     */
    private static function file(string $contents)
    {
        $resource = fopen('php://memory', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $contents);
        rewind($resource);

        return $resource;
    }
}
