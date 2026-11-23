<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use SightPress\Module\Console\Command;

/**
 * A core command that takes any number of file or folder paths after its other arguments. They are read with
 * {@see ConsoleCommandInput::paths()}.
 */
interface TakesPaths extends Command
{
    public const string ARGUMENT = 'paths';
}
