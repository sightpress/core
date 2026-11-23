<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Template\Message;

/**
 * Checks a WebVTT captions file line by line, as a stream, and refuses it with the number of the first line that is
 * wrong: the `WEBVTT` header, UTF-8 text, and cues whose timings are real, end after they start and don't start
 * before the cue above. `NOTE`, `STYLE` and `REGION` blocks are skipped.
 */
final class WebVtt
{
    private const int CHUNK_BYTES = 8192;

    private const int MAX_LINE_BYTES = 65536;

    private const string TIME = '(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})';

    /**
     * @throws MediaException when the file isn't valid WebVTT
     */
    public static function check(string $path): void
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new MediaException('The uploaded file can\'t be read. Try uploading it again.');
        }
        try {
            self::lines($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     */
    private static function lines($stream): void
    {
        $number = 0;
        $previousStart = 0.0;
        $inHeader = true;
        $blockStart = true;
        $skipping = false;
        $idSeen = false;
        foreach (self::read($stream) as $line) {
            ++$number;
            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw self::refused($number, new Message('the line is too long'));
            }
            if (preg_match('//u', $line) !== 1) {
                throw self::refused($number, new Message('it isn\'t UTF-8 text'));
            }
            if ($number === 1) {
                $first = str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
                if (preg_match('/^WEBVTT(?:[ \t].*)?$/', $first) !== 1) {
                    throw self::refused(1, new Message('the first line must be "WEBVTT"'));
                }
                continue;
            }
            if ($line === '') {
                $inHeader = false;
                $blockStart = true;
                $skipping = false;
                $idSeen = false;
                continue;
            }
            if ($inHeader || $skipping) {
                continue;
            }
            if (str_contains($line, '-->')) {
                $previousStart = self::timing($line, $number, $previousStart);
                $blockStart = false;
                $idSeen = false;
                $skipping = true;
                continue;
            }
            if ($blockStart && preg_match('/^(?:NOTE|STYLE|REGION)(?:[ \t]|$)/', $line) === 1) {
                $skipping = true;
                $blockStart = false;
                continue;
            }
            if (!$blockStart) {
                throw self::refused($number, new Message('a timing line such as "00:01.000 --> 00:04.000" is missing'));
            }
            $blockStart = false;
            $idSeen = true;
        }
        if ($number === 0) {
            throw self::refused(1, new Message('the first line must be "WEBVTT"'));
        }
        if ($idSeen) {
            throw self::refused($number, new Message('a timing line such as "00:01.000 --> 00:04.000" is missing'));
        }
    }

    private static function timing(string $line, int $number, float $previousStart): float
    {
        $pattern = '/^' . self::TIME . '[ \t]+-->[ \t]+' . self::TIME . '(?:[ \t].*)?$/';
        if (preg_match($pattern, $line, $time) !== 1) {
            throw self::refused($number, new Message('the timing must look like "00:01.000 --> 00:04.000"'));
        }
        $start = self::seconds($time[1], $time[2], $time[3], $time[4]);
        $end = self::seconds($time[5], $time[6], $time[7], $time[8]);
        if ($end <= $start) {
            throw self::refused($number, new Message('the cue must end after it starts'));
        }
        if ($start < $previousStart) {
            throw self::refused($number, new Message('the cue starts before the one above it'));
        }

        return $start;
    }

    private static function seconds(string $hours, string $minutes, string $seconds, string $milliseconds): float
    {
        return (int) $hours * 3600 + (int) $minutes * 60 + (int) $seconds + (int) $milliseconds / 1000;
    }

    private static function refused(int $line, Message $reason): MediaException
    {
        return new MediaException(new Message('This captions file has a mistake on line {line}: {reason}.', ['line' => (string) $line, 'reason' => $reason]));
    }

    /**
     * The file's lines without their endings (LF, CRLF or CR), even when an ending falls between two chunks. A line
     * longer than the limit is cut there, which is enough for it to be refused.
     *
     * @param resource $stream
     *
     * @return \Generator<int, string>
     */
    private static function read($stream): \Generator
    {
        $buffer = '';
        while (($chunk = fread($stream, self::CHUNK_BYTES)) !== false && $chunk !== '') {
            $buffer .= $chunk;
            // A CR at the very end may be half of a CRLF: keep it for the next chunk.
            $held = str_ends_with($buffer, "\r") && !feof($stream) ? "\r" : '';
            $text = $held === '' ? $buffer : substr($buffer, 0, -1);
            $parts = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
            $last = (string) array_pop($parts);
            foreach ($parts as $part) {
                yield $part;
            }
            $buffer = strlen($last) > self::MAX_LINE_BYTES ? substr($last, 0, self::MAX_LINE_BYTES + 1) : $last . $held;
        }
        if ($buffer !== '') {
            yield from explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($buffer, "\r")));
        }
    }
}
