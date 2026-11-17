<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Cli;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Cli\ConsoleApplicationFactory;
use SightPress\Core\Cli\ConsoleCommandInput;
use SightPress\Core\Cli\ConsoleCommandOutput;
use SightPress\Core\Cli\ModuleCommand;
use SightPress\Core\Cli\ParameterValues;
use SightPress\Core\Cli\Questions;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\GuidedCommand;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use SightPress\Module\ModuleRegistrar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(Questions::class)]
#[CoversClass(ParameterValues::class)]
#[UsesClass(ModuleCommand::class)]
#[UsesClass(ConsoleApplicationFactory::class)]
#[UsesClass(ConsoleCommandInput::class)]
#[UsesClass(ConsoleCommandOutput::class)]
#[UsesClass(Services::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class QuestionsTest extends TestCase
{
    public function testAsksEachQuestionWithItsChoicesAndDefaultAndReadsTheAnswersBack(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', 's3cret', 's3cret', 'pt_BR', 'no', 'EN_gb', '', 'maybe', '', 'yes']);

        $status = $tester->run(['command' => 'example:guided'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "Which engine? Type sqlite or postgresql. Press Enter for sqlite.\n> "
            . "Which file? Press Enter for /srv/sqlite.db.\n> "
            . "Which password? What you type is not shown.\n> \n"
            . "Type it again to check it. What you type is not shown.\n> \n"
            . "Which language?\n> "
            . "That is Brazilian Portuguese (pt-br). Is that right? Type yes or no. Press Enter for yes.\n> "
            . "Which language?\n> "
            . "That is British English (en-gb). Is that right? Type yes or no. Press Enter for yes.\n> "
            . "Add a demo? Type yes or no. Press Enter for no.\n> Please type yes or no.\n"
            . "Add a demo? Type yes or no. Press Enter for no.\n> \n"
            . "Here are your answers:\n"
            . "Engine: sqlite\n"
            . "File: /srv/sqlite.db\n"
            . "Password: typed, not shown\n"
            . "Language: British English (en-gb)\n"
            . "Add a demo: no\n"
            . "Is all of this right? If you answer no, you start again from the first question. Type yes or no. Press Enter for yes.\n> "
            . "engine=sqlite path=/srv/sqlite.db host=- password=s3cret language=en-gb create=no demo=no\n",
            $tester->getDisplay(),
        );
    }

    public function testNoStartsAgainWithTheAnswersSoFarAsDefaults(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'postgresql', 'db', '', 'xx', 'no', '', 'no',
            '', '', '', '', 'yes', '', '',
        ]);

        $status = $tester->run(['command' => 'example:guided'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString("Server: db\nPassword: none\nLanguage: xx\nCreate it: no\nAdd a demo: no\n", $display);
        self::assertStringContainsString("Then let's start again. Press Enter to keep an answer.\nWhich engine? Type sqlite or postgresql. Press Enter for postgresql.\n", $display);
        self::assertStringContainsString("Name of the server. Press Enter for db.\n> ", $display, 'the colon becomes a full stop before a hint');
        self::assertStringContainsString("Which password? What you type is not shown.\n", $display);
        self::assertStringContainsString("Which language? Press Enter for xx.\n", $display);
        self::assertStringContainsString("Create it? Type yes or no. Press Enter for no.\n> ", $display);
        self::assertStringContainsString("Add a demo? Type yes or no. Press Enter for no.\n> ", $display);
        self::assertStringEndsWith("engine=postgresql path=- host=db password=- language=xx create=yes demo=no\n", $display);
    }

    public function testASecretTypedBeforeIsKeptWhenStartingAgain(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', 'pw', 'pw', 'xx', 'no', 'no', '', '', '', '', '', 'yes']);

        $tester->run(['command' => 'example:guided'], ['interactive' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString("Which password? Press Enter to keep the one you typed. What you type is not shown.\n", $display);
        self::assertStringEndsWith("password=pw language=xx create=no demo=no\n", $display);
    }

    public function testASecretIsTypedTwiceAndAskedAgainWhenTheTwoDiffer(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', 'pw', 'pW', 'pw2', 'pw2', 'xx', '', 'yes']);

        $status = $tester->run(['command' => 'example:guided'], ['interactive' => true, 'capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(
            "Which password? What you type is not shown.\n> \n"
            . "Type it again to check it. What you type is not shown.\n> \n"
            . "The two did not match, so it was not kept. Type it again.\n"
            . "Which password? What you type is not shown.\n> \n"
            . "Type it again to check it. What you type is not shown.\n> \n"
            . "Which language?",
            $tester->getErrorOutput(),
        );
        self::assertStringNotContainsString('pw', $tester->getErrorOutput(), 'neither typing is shown');
        self::assertStringEndsWith("password=pw2 language=xx create=no demo=no\n", $tester->getDisplay());
    }

    public function testThreeSecretsThatDoNotMatchStopTheCommand(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', 'a', 'b', 'c', 'd', 'e', 'f']);

        $status = $tester->run(['command' => 'example:guided'], ['interactive' => true, 'capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame(3, substr_count($tester->getErrorOutput(), 'The two did not match'));
        self::assertStringContainsString('That answer was not accepted 3 times', $tester->getErrorOutput());
        self::assertStringNotContainsString('engine=', $tester->getDisplay());
    }

    public function testThreeWrongAnswersAfterStartingAgainStopTheCommand(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', '', 'xx', '', 'no', '', '', '', 'french', 'german', 'dutch']);

        $status = $tester->run(['command' => 'example:guided', '--key' => 'k'], ['interactive' => true, 'capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('"dutch" is not a language.', $tester->getErrorOutput());
        self::assertStringContainsString("Key for scripts: typed, not shown\n", $tester->getDisplay());
        self::assertStringNotContainsString('engine=', $tester->getDisplay());
    }

    public function testAWrongValueOnTheCommandLineIsExplainedAndAskedForAgain(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['en_gb', 'yes', 'yes']);

        $status = $tester->run(
            ['command' => 'example:guided', '--engine' => 'SQLite', '--path' => '/x.db', '--language' => 'french', '--force' => true],
            ['interactive' => true, 'capture_stderr_separately' => true],
        );

        self::assertSame(Command::SUCCESS, $status, $tester->getErrorOutput() . $tester->getDisplay());
        self::assertStringStartsWith("\"french\" is not a language.\nWhich language?\n> ", $tester->getErrorOutput(), 'questions go to standard error, like errors');
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('Which engine?', $display, 'what was given and accepted is not asked');
        self::assertStringNotContainsString('Add a demo?', $display, 'optional questions are only asked when something required is missing');
        self::assertStringContainsString("Engine: sqlite\nFile: /x.db\nPassword: none\nLanguage: British English (en-gb)\nAdd a demo: no\nSkip checks: yes\n", $display);
        self::assertStringEndsWith("engine=sqlite path=/x.db host=- password=- language=en-gb create=no demo=no\n", $display);
    }

    public function testNothingIsAskedWhenEverythingIsGivenAndAccepted(): void
    {
        $tester = $this->tester();

        $status = $tester->run(['command' => 'example:guided', '--engine' => 'postgresql', '--host' => 'db', '--language' => 'pt_BR'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("engine=postgresql path=- host=db password=- language=pt-br create=no demo=no\n", $tester->getDisplay());
    }

    public function testWithoutATerminalDefaultsApplyAndWrongValuesStopTheCommand(): void
    {
        $tester = $this->tester();

        $status = $tester->run(['command' => 'example:guided', '--language' => 'EN-GB', '--host' => 'ignored'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("engine=sqlite path=/srv/sqlite.db host=- password=- language=en-gb create=no demo=no\n", $tester->getDisplay());

        $status = $tester->run(['command' => 'example:guided', '--language' => 'en', '--engine' => 'oracle'], ['interactive' => false, 'capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("\"oracle\" is not one of the choices: type sqlite or postgresql.\n", $tester->getErrorOutput());
    }

    public function testARequiredArgumentIsNamedAsOne(): void
    {
        $input = new ArrayInput([], new InputDefinition());
        $values = new ParameterValues($input);

        $this->expectExceptionMessage('The slug argument is required.');

        $values->accept(new CommandParameter('slug', 'Slug:', required: true), ' ');
    }

    public function testAnOptionalBlankAnswerHasNoValueAndErrorsGoToTheOnlyOutput(): void
    {
        $input = new ArrayInput([], new InputDefinition([new InputOption('note', null, InputOption::VALUE_REQUIRED)]));
        $output = new BufferedOutput();
        $input->setStream(self::stream("\n\n\n"));
        $note = new CommandParameter('note', 'A note:', CommandParameterType::Option, required: true, ask: true);

        self::assertNull(new ParameterValues($input)->accept(new CommandParameter('note', 'A note:', CommandParameterType::Option), ''));
        self::assertFalse(new Questions([$note], $input, $output)->ask());
        self::assertStringContainsString("A note:\n> The --note option is required.\n", $output->fetch());
        self::assertSame('a', ParameterValues::either(['a']));
        self::assertSame('a, b or c', ParameterValues::either(['a', 'b', 'c']));
    }

    /**
     * @return resource
     */
    private static function stream(string $text)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }

    private function tester(): ApplicationTester
    {
        $module = new FakeModule('first', register: static fn (ModuleRegistrar $r) => $r->commands()->add(GuidedCommand::class));
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'sightpress/core')])->resolve(new ModuleSelection()));
        $application = new ConsoleApplicationFactory(new Container(), $registry)->create('1.0.0');
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }
}
