<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Module\Console\CommandOutput;

/**
 * A command's output, kept for assertions.
 */
final class RecordingOutput implements CommandOutput
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $text = ''): void
    {
        $this->lines[] = $text;
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
    }
}
