<?php

declare(strict_types=1);

namespace SightPress\Core\Cli;

use InvalidArgumentException;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Asks a module command's questions at the terminal, as {@see CommandParameter} describes: each question shows its
 * choices and its default, an answer that is not accepted is explained and asked again (at most {@see ATTEMPTS}
 * times), a secret (a password or a key) is typed twice and both must match, an answer with a plain-language meaning
 * is read back to confirm, and at the end every answer is listed for one last check — "no" starts again from the first question with the answers so far as defaults.
 *
 * Nothing is asked when the command line already has every required value and they are all accepted.
 * Every line is a plain sentence a screen reader can read out: no colours, tables or redrawn lines.
 */
final class Questions
{
    public const int ATTEMPTS = 3;

    private readonly ParameterValues $values;

    private bool $asked = false;

    /**
     * @param list<CommandParameter> $parameters
     */
    public function __construct(
        private readonly array $parameters,
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        private readonly QuestionHelper $helper = new QuestionHelper(),
    ) {
        $this->values = new ParameterValues($input);
    }

    /**
     * When the input runs out, every further question takes its default, so a missing required answer ends in
     * the command stopping.
     *
     * @return bool false when the command must stop: an answer was refused too often
     */
    public function ask(): bool
    {
        if (!$this->round($this->needed())) {
            return false;
        }
        while ($this->asked && !$this->confirmed()) {
            $this->output->writeln('Then let\'s start again. Press Enter to keep an answer.');
            if (!$this->round(true, again: true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a question must be asked because a required value is missing. Given values are normalised on the
     * way, so the parameters after them know whether they apply.
     */
    private function needed(): bool
    {
        foreach ($this->parameters as $parameter) {
            if (!$this->values->applies($parameter)) {
                continue;
            }
            $given = $this->values->given($parameter);
            if ($given === null && $parameter->required && $parameter->ask) {
                return true;
            }
            if ($given !== null && $parameter->type !== CommandParameterType::Flag) {
                try {
                    $this->values->store($parameter, $this->values->accept($parameter, $given));
                } catch (InvalidArgumentException) {
                    // explained when the round reaches it
                }
            }
        }

        return false;
    }

    /**
     * Goes through every parameter once: asks the ones that need asking and stores what is accepted.
     *
     * @param bool $guided ask every parameter that {@see CommandParameter::$ask}s and was not given
     * @param bool $again  ask them even when given, with the current value as the default
     */
    private function round(bool $guided, bool $again = false): bool
    {
        foreach ($this->parameters as $parameter) {
            if (!$this->values->applies($parameter)) {
                $this->values->store($parameter, null);
                continue;
            }
            $current = $this->values->given($parameter);
            $ask = $parameter->ask && ($again || ($guided && $current === null));
            if (!$ask) {
                try {
                    if ($parameter->type !== CommandParameterType::Flag) {
                        $this->values->store($parameter, $this->values->accept($parameter, $current ?? ''));
                    }
                    continue;
                } catch (InvalidArgumentException $exception) {
                    $this->error($exception->getMessage());
                    $current = null;
                }
            }
            $previous = $parameter->type === CommandParameterType::Flag ? $current ?? 'no' : $current;
            if (!$this->askFor($parameter, $again ? $previous : null)) {
                return false;
            }
        }

        return true;
    }

    private function askFor(CommandParameter $parameter, ?string $previous): bool
    {
        $this->asked = true;
        if ($parameter->type === CommandParameterType::Flag) {
            $this->values->store($parameter, $this->yesOrNo($parameter->description, $previous ?? $this->values->default($parameter) ?? 'no'));

            return true;
        }

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $answer = $this->prompt($parameter, $previous);
            $kept = $answer === '' && $previous !== null;
            try {
                $value = $this->values->accept($parameter, $kept ? $previous : $answer);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());
                continue;
            }
            if ($parameter->secret && !$kept && $value !== null && !$this->typedTwice($answer)) {
                continue;
            }
            if ($value !== null && !$this->meaningConfirmed($parameter, $value)) {
                --$attempt;
                continue;
            }
            $this->values->store($parameter, $value);

            return true;
        }

        $this->error(sprintf(
            'That answer was not accepted %d times, so the command stopped without changing anything. Run it again when you have the details.',
            self::ATTEMPTS,
        ));

        return false;
    }

    private function prompt(CommandParameter $parameter, ?string $previous): string
    {
        $hints = '';
        if ($parameter->choices !== []) {
            $hints .= sprintf(' Type %s.', ParameterValues::either($parameter->choices));
        }
        $default = $previous ?? $this->values->default($parameter);
        if ($parameter->secret) {
            $hints .= $previous !== null ? ' Press Enter to keep the one you typed.' : ($default === null ? '' : ' Press Enter to keep the default.');
            $hints .= ' What you type is not shown.';
        } elseif ($default !== null) {
            $hints .= sprintf(' Press Enter for %s.', $default);
        }
        $text = $parameter->description;
        if ($hints !== '' && str_ends_with($text, ':')) {
            // "Name of the site: Press Enter for demo." reads as if the hint were the answer.
            $text = substr($text, 0, -1) . '.';
        }

        $question = new Question($text . $hints . "\n> ", '');
        $question->setHidden($parameter->secret);
        $question->setTrimmable(true);
        $answer = $this->helper->ask($this->input, $this->output, $question);

        return is_string($answer) ? trim($answer) : '';
    }

    /**
     * Asks for a secret a second time, hidden like the first, so a typing mistake nobody can see is caught.
     */
    private function typedTwice(string $answer): bool
    {
        $question = new Question("Type it again to check it. What you type is not shown.\n> ", '');
        $question->setHidden(true);
        $question->setTrimmable(true);
        $again = $this->helper->ask($this->input, $this->output, $question);
        if ((is_string($again) ? trim($again) : '') === $answer) {
            return true;
        }
        $this->error('The two did not match, so it was not kept. Type it again.');

        return false;
    }

    private function meaningConfirmed(CommandParameter $parameter, string $value): bool
    {
        $meaning = $parameter->describe === null ? null : ($parameter->describe)($value);
        if ($meaning === null) {
            return true;
        }

        return $this->yesOrNo(sprintf('That is %s (%s). Is that right?', $meaning, $value), 'yes') === 'yes';
    }

    private function confirmed(): bool
    {
        $this->output->writeln('');
        $this->output->writeln('Here are your answers:');
        foreach ($this->parameters as $parameter) {
            if (!$this->values->applies($parameter)) {
                continue;
            }
            $value = $this->values->given($parameter);
            if ($parameter->type === CommandParameterType::Flag) {
                if (!$parameter->ask && $value === null) {
                    continue;
                }
                $value ??= 'no';
            } elseif ($value === null) {
                if (!$parameter->secret || !$parameter->ask) {
                    continue;
                }
                $value = 'none';
            } elseif ($parameter->secret) {
                $value = 'typed, not shown';
            } else {
                $meaning = $parameter->describe === null ? null : ($parameter->describe)($value);
                $value = $meaning === null ? $value : sprintf('%s (%s)', $meaning, $value);
            }
            $this->output->writeln(sprintf('%s: %s', $parameter->label, $value));
        }

        return $this->yesOrNo('Is all of this right? If you answer no, you start again from the first question.', 'yes') === 'yes';
    }

    /**
     * @return 'yes'|'no'
     */
    private function yesOrNo(string $text, string $default): string
    {
        while (true) {
            $question = new Question(sprintf("%s Type yes or no. Press Enter for %s.\n> ", $text, $default), $default);
            $answer = $this->helper->ask($this->input, $this->output, $question);
            $answer = mb_strtolower(trim(is_string($answer) ? $answer : ''));
            if (in_array($answer, ['yes', 'y'], true)) {
                return 'yes';
            }
            if (in_array($answer, ['no', 'n'], true)) {
                return 'no';
            }
            $this->error('Please type yes or no.');
        }
    }

    private function error(string $text): void
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $output->writeln($text, OutputInterface::OUTPUT_RAW);
    }
}
