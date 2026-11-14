<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * A command for console tests: echoes what it was given.
 */
final class ExampleCommand implements Command
{
    public function name(): string
    {
        return 'example:echo';
    }

    public function description(): string
    {
        return 'Writes back what it was given.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'The slug.', CommandParameterType::Argument, required: true),
            new CommandParameter('locale', 'The locale.', CommandParameterType::Option, required: true),
            new CommandParameter('owner', 'The owner.', CommandParameterType::Option),
            new CommandParameter('force', 'Skip the question.', CommandParameterType::Flag),
            new CommandParameter('extra', 'An optional argument.'),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $output->line(sprintf(
            'slug=%s locale=%s owner=%s force=%s extra=%s missing=%s',
            $input->argument('slug') ?? '-',
            $input->option('locale') ?? '-',
            $input->option('owner') ?? '-',
            $input->flag('force') ? 'yes' : 'no',
            $input->argument('extra') ?? '-',
            $input->option('missing') ?? $input->argument('missing') ?? ($input->flag('missing') ? 'yes' : '-'),
        ));
        $output->error('<b>not markup</b>');

        return self::SUCCESS;
    }
}
