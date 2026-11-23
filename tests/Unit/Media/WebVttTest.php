<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\WebVtt;

#[CoversClass(WebVtt::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Media')]
final class WebVttTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'sp-vtt-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'only the header' => ['WEBVTT'];
        yield 'a title and a byte order mark' => ["\xEF\xBB\xBFWEBVTT - Harbour tour\n"];
        yield 'header metadata' => ["WEBVTT\nKind: captions\nLanguage: en\n\n00:01.000 --> 00:02.000\nHello\n"];
        yield 'ids, hours and settings' => ["WEBVTT\n\nfirst\n01:00:00.000 --> 01:00:01.500 line:0 align:start\nHello\nagain\n\n01:00:01.000 --> 01:00:02.000\nOverlap\n"];
        yield 'notes, styles and regions' => ["WEBVTT\n\nNOTE a\nsecond line\n\nSTYLE\n::cue { color: red }\n\nREGION\nid:a\n\nNOTE\n\n00:01.000 --> 00:02.000\nHello\n"];
        yield 'Windows line endings' => ["WEBVTT\r\n\r\n00:01.000 --> 00:02.000\r\nHello\r\n"];
        yield 'old Mac line endings' => ["WEBVTT\r\r00:01.000 --> 00:02.000\rHello\r"];
        yield 'a line ending between two chunks' => ["WEBVTT\n\nNOTE " . str_repeat('x', 8192 - 14) . "\r\n\r\n00:01.000 --> 00:02.000\r\nHello\r\n"];
    }

    #[DataProvider('valid')]
    public function testAcceptsValidFiles(string $contents): void
    {
        file_put_contents($this->file, $contents);

        WebVtt::check($this->file);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => ['', 1, 'the first line must be "WEBVTT"'];
        yield 'no header' => ["00:01.000 --> 00:02.000\nHello\n", 1, 'the first line must be "WEBVTT"'];
        yield 'a word after the header' => ["WEBVTTX\n", 1, 'the first line must be "WEBVTT"'];
        yield 'not UTF-8' => ["WEBVTT\n\n00:01.000 --> 00:02.000\n\xFF\n", 4, 'it isn\'t UTF-8 text'];
        yield 'a line too long' => ["WEBVTT\n\n00:01.000 --> 00:02.000\n" . str_repeat('x', 70000) . "\n", 4, 'the line is too long'];
        yield 'a line too long at the end' => ["WEBVTT\n\n00:01.000 --> 00:02.000\n" . str_repeat('x', 70000), 4, 'the line is too long'];
        yield 'seconds out of range' => ["WEBVTT\n\n00:01.000 --> 00:99.000\nHello\n", 3, 'the timing must look like "00:01.000 --> 00:04.000"'];
        yield 'ends before it starts' => ["WEBVTT\n\n00:05.000 --> 00:04.000\nHello\n", 3, 'the cue must end after it starts'];
        yield 'ends as it starts' => ["WEBVTT\n\n00:05.000 --> 00:05.000\nHello\n", 3, 'the cue must end after it starts'];
        yield 'out of order' => ["WEBVTT\n\n00:05.000 --> 00:06.000\nA\n\n00:01.000 --> 00:02.000\nB\n", 6, 'the cue starts before the one above it'];
        yield 'an id and no timing' => ["WEBVTT\n\nfirst\nHello\n", 4, 'a timing line such as "00:01.000 --> 00:04.000" is missing'];
        yield 'an id at the end' => ["WEBVTT\n\nfirst\n", 3, 'a timing line such as "00:01.000 --> 00:04.000" is missing'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAMistakeWithItsLine(string $contents, int $line, string $reason): void
    {
        file_put_contents($this->file, $contents);

        try {
            WebVtt::check($this->file);
            self::fail('The file was accepted.');
        } catch (MediaException $refused) {
            self::assertSame(sprintf('This captions file has a mistake on line %d: %s.', $line, $reason), $refused->getMessage());
        }
    }

    public function testSaysSoWhenTheFileCantBeRead(): void
    {
        unlink($this->file);

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('The uploaded file can\'t be read.');
        WebVtt::check($this->file);
    }
}
