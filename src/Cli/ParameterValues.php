<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use InvalidArgumentException;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Reads, checks and stores the values of a module command's parameters on the parsed command line, by the rules of
 * {@see CommandParameter}: whether it applies, its default, its choices and its normaliser. Used both when nobody
 * is at the terminal and by {@see Questions} for every answer.
 */
final readonly class ParameterValues
{
    public CommandInput $answers;

    public function __construct(private InputInterface $input)
    {
        $this->answers = new ConsoleCommandInput($input);
    }

    /**
     * Checks every parameter in order and stores the accepted values, so the command gets them normalised.
     *
     * @param list<CommandParameter> $parameters
     *
     * @return string|null what is wrong, in one plain sentence; null when everything is accepted
     */
    public function settle(array $parameters): ?string
    {
        foreach ($parameters as $parameter) {
            if ($parameter->type === CommandParameterType::Flag) {
                continue;
            }
            if (!$this->applies($parameter)) {
                $this->store($parameter, null);
                continue;
            }
            try {
                $this->store($parameter, $this->accept($parameter, $this->given($parameter) ?? ''));
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    public function applies(CommandParameter $parameter): bool
    {
        return $parameter->when === null || ($parameter->when)($this->answers);
    }

    /**
     * The value on the command line, or null when it is missing or blank. A flag is "yes" when given.
     */
    public function given(CommandParameter $parameter): ?string
    {
        $value = match ($parameter->type) {
            CommandParameterType::Argument => $this->answers->argument($parameter->name),
            CommandParameterType::Option => $this->answers->option($parameter->name),
            CommandParameterType::Flag => $this->answers->flag($parameter->name) ? 'yes' : null,
        };

        return $value === null || trim($value) === '' ? null : $value;
    }

    public function default(CommandParameter $parameter): ?string
    {
        $default = $parameter->default instanceof \Closure ? ($parameter->default)($this->answers) : $parameter->default;

        return $default === null || trim($default) === '' ? null : $default;
    }

    /**
     * Turns an answer (blank means the default) into the value the command gets: null for a blank optional one.
     *
     * @throws InvalidArgumentException with a plain sentence when the answer is not accepted
     */
    public function accept(CommandParameter $parameter, string $answer): ?string
    {
        $value = trim($answer);
        if ($value === '') {
            $value = $this->default($parameter) ?? '';
        }
        if ($value === '') {
            if ($parameter->required) {
                throw new InvalidArgumentException(self::requiredMessage($parameter));
            }

            return null;
        }
        if ($parameter->choices !== []) {
            $value = self::choice($parameter, $value);
        }

        return $parameter->normalise === null ? $value : ($parameter->normalise)($value, $this->answers);
    }

    public function store(CommandParameter $parameter, ?string $value): void
    {
        match ($parameter->type) {
            CommandParameterType::Argument => $this->input->setArgument($parameter->name, $value),
            CommandParameterType::Option => $this->input->setOption($parameter->name, $value),
            CommandParameterType::Flag => $this->input->setOption($parameter->name, $value === 'yes'),
        };
    }

    /**
     * "a", "a or b", "a, b or c".
     *
     * @param list<string> $words
     */
    public static function either(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words) . ' or ' . $last;
    }

    private static function choice(CommandParameter $parameter, string $value): string
    {
        foreach ($parameter->choices as $choice) {
            if (mb_strtolower($choice) === mb_strtolower($value)) {
                return $choice;
            }
        }

        throw new InvalidArgumentException(sprintf('"%s" is not one of the choices: type %s.', $value, self::either($parameter->choices)));
    }

    private static function requiredMessage(CommandParameter $parameter): string
    {
        return $parameter->type === CommandParameterType::Option
            ? sprintf('The --%s option is required.', $parameter->name)
            : sprintf('The %s argument is required.', $parameter->name);
    }
}
