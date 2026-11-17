<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use InvalidArgumentException;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * A command that uses every way of asking: choices, follow-up questions, defaults worked out from earlier answers,
 * a read-back meaning, a secret and yes-or-no flags.
 */
final class GuidedCommand implements Command
{
    private const array LANGUAGES = ['en-gb' => 'British English', 'pt-br' => 'Brazilian Portuguese', 'xx' => null];

    public function name(): string
    {
        return 'example:guided';
    }

    public function description(): string
    {
        return 'Asks its questions.';
    }

    public function parameters(): array
    {
        $option = CommandParameterType::Option;
        $file = static fn (CommandInput $answers): bool => $answers->option('engine') === 'sqlite';

        return [
            new CommandParameter('engine', 'Which engine?', $option, true, label: 'Engine', default: 'sqlite', choices: ['sqlite', 'postgresql']),
            new CommandParameter('path', 'Which file?', $option, true, label: 'File', default: static fn (CommandInput $answers): string => '/srv/' . $answers->option('engine') . '.db', when: $file),
            new CommandParameter('host', 'Name of the server:', $option, true, label: 'Server', default: 'localhost', when: static fn (CommandInput $answers): bool => !$file($answers)),
            new CommandParameter('password', 'Which password?', $option, label: 'Password', secret: true, ask: true),
            new CommandParameter(
                'language',
                'Which language?',
                $option,
                true,
                label: 'Language',
                normalise: static fn (string $value): string => array_key_exists(mb_strtolower(strtr($value, '_', '-')), self::LANGUAGES)
                    ? mb_strtolower(strtr($value, '_', '-'))
                    : throw new InvalidArgumentException(sprintf('"%s" is not a language.', $value)),
                describe: static fn (string $value): ?string => self::LANGUAGES[$value] ?? null,
            ),
            new CommandParameter('create', 'Create it?', CommandParameterType::Flag, label: 'Create it', default: 'yes', when: static fn (CommandInput $answers): bool => !$file($answers), ask: true),
            new CommandParameter('demo', 'Add a demo?', CommandParameterType::Flag, label: 'Add a demo', ask: true),
            new CommandParameter('key', 'A key for scripts.', $option, label: 'Key for scripts', secret: true),
            new CommandParameter('force', 'Skip checks.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $output->line(sprintf(
            'engine=%s path=%s host=%s password=%s language=%s create=%s demo=%s',
            $input->option('engine') ?? '-',
            $input->option('path') ?? '-',
            $input->option('host') ?? '-',
            $input->option('password') ?? '-',
            $input->option('language') ?? '-',
            $input->flag('create') ? 'yes' : 'no',
            $input->flag('demo') ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
