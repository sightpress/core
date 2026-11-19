<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `system:check`: the server's upload and input limits, the largest file of each kind that can be uploaded, and
 * the settings to change ({@see ServerLimitsCheck}). Exits with 1 when something must be fixed, for CI and
 * monitoring.
 *
 * Run it the way the web server runs PHP (in the skeleton: `docker compose exec php bin/sightpress system:check`):
 * the command line can have its own php.ini.
 */
final readonly class SystemCheckCommand implements Command
{
    public function __construct(
        private ServerLimits $server,
        private MediaLimits $limits,
        private ServerLimitsCheck $check,
    ) {
    }

    public function name(): string
    {
        return 'system:check';
    }

    public function description(): string
    {
        return 'Checks the server\'s upload and input limits and says which settings to change.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $server = $this->server;
        $allowance = new UploadAllowance($this->limits, $server);
        $request = $server->requestLimit();

        $output->line('Upload limits');
        $output->line(sprintf('  Largest file in one request: %s', self::size($request)));
        $output->line(sprintf(
            '    upload_max_filesize %s, post_max_size %s, web server %s',
            self::size($server->uploadMaxFilesize),
            self::size($server->postMaxSize),
            $server->proxyBodyLimit === null ? 'not stated' : MediaLimits::describe($server->proxyBodyLimit),
        ));
        foreach (MediaKind::cases() as $kind) {
            $max = $allowance->maxBytes($kind);
            $line = sprintf('  %s: %s', ucfirst((string) UploadAllowance::plural($kind)), MediaLimits::describe($max));
            if ($allowance->limitedByServer($kind)) {
                $line .= sprintf(' (the site allows %s)', MediaLimits::describe($this->limits->maxBytes($kind)));
            }
            $output->line($line);
        }
        $output->line(sprintf(
            '  memory_limit %s, max_input_vars %d, max_file_uploads %d',
            self::size($server->memoryLimit),
            $server->maxInputVars,
            $server->maxFileUploads,
        ));
        $output->line(sprintf(
            '  Folder for files being uploaded: %s (%s)',
            $server->tempDir,
            match (true) {
                !$server->tempWritable => 'missing or not writable',
                $server->tempFree === null => 'free space unknown',
                default => MediaLimits::describe($server->tempFree) . ' free',
            },
        ));

        foreach ($this->check->notes() as $note) {
            $output->line('Note: ' . $note);
        }

        $problems = $this->check->problems();
        foreach ($problems as $problem) {
            $output->error((string) $problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        $output->line('No problems found.');

        return self::SUCCESS;
    }

    private static function size(?int $bytes): string
    {
        return $bytes === null ? 'no limit' : MediaLimits::describe($bytes);
    }
}
