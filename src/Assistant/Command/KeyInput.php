<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

/**
 * Reads an API key from standard input (`--key-stdin`), so it never appears in the shell history or the process
 * list: `printf %s "$KEY" | bin/sightpress site:assistant:set …`. Only the first line counts.
 */
final readonly class KeyInput
{
    public function __construct(private string $stream = 'php://stdin')
    {
    }

    public function read(): string
    {
        $handle = @fopen($this->stream, 'r');
        if ($handle === false) {
            return '';
        }
        $line = fgets($handle, 4096);
        fclose($handle);

        return trim($line === false ? '' : $line);
    }
}
