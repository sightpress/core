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
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleCommand;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\ModuleRegistrar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(ConsoleApplicationFactory::class)]
#[CoversClass(ModuleCommand::class)]
#[CoversClass(ConsoleCommandInput::class)]
#[CoversClass(ConsoleCommandOutput::class)]
#[UsesClass(Services::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class ConsoleTest extends TestCase
{
    public function testRunsAModuleCommandWithItsParameters(): void
    {
        $tester = $this->tester(static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class));

        $status = $tester->run([
            'command' => 'example:echo',
            'slug' => 'demo',
            '--locale' => 'en-GB',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("slug=demo locale=en-GB owner=- force=no extra=- missing=-\n", $tester->getDisplay());
        self::assertSame("<b>not markup</b>\n", $tester->getErrorOutput());
    }

    public function testAFlagIsOnlyOnWhenGiven(): void
    {
        $tester = $this->tester(static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class));

        $tester->run(['command' => 'example:echo', 'slug' => 'demo', '--locale' => 'cy', '--owner' => 'org', 'extra' => 'more', '--force' => true]);

        self::assertStringContainsString('owner=org force=yes extra=more', $tester->getDisplay());
    }

    public function testARequiredOptionMustBeGiven(): void
    {
        $tester = $this->tester(static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class));

        $status = $tester->run(['command' => 'example:echo', 'slug' => 'demo'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame("The --locale option is required.\n", $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testTheHelpListsTheParameters(): void
    {
        $tester = $this->tester(static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class));

        $tester->run(['command' => 'help', 'command_name' => 'example:echo']);

        $help = $tester->getDisplay();
        self::assertStringContainsString('Writes back what it was given.', $help);
        self::assertStringContainsString('The locale. (required)', $help);
        self::assertStringContainsString('Skip the question.', $help);
    }

    public function testTwoModulesCantAddCommandsWithTheSameName(): void
    {
        $container = new Container();
        $registry = $this->registry(
            static fn (ModuleRegistrar $r) => $r->commands()->add(ExampleCommand::class),
            static fn (ModuleRegistrar $r) => $r->commands()->add(SameNameCommand::class),
        );

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Command "example:echo" is registered twice: by module "first" and by module "second".');

        new ConsoleApplicationFactory($container, $registry)->create();
    }

    public function testErrorsGoToTheOnlyStreamWhenThereIsNoErrorStream(): void
    {
        $buffer = new BufferedOutput();
        $output = new ConsoleCommandOutput($buffer);

        $output->line('one');
        $output->error('two');
        $output->line();

        self::assertSame("one\ntwo\n\n", $buffer->fetch());
    }

    public function testUndeclaredParametersAreMissing(): void
    {
        $input = new ConsoleCommandInput(new ArrayInput([]));

        self::assertNull($input->argument('slug'));
        self::assertNull($input->option('locale'));
        self::assertFalse($input->flag('force'));
    }

    /**
     * @param \Closure(ModuleRegistrar): void $register
     */
    private function tester(\Closure $register): ApplicationTester
    {
        $application = new ConsoleApplicationFactory(new Container(), $this->registry($register))->create('1.0.0');
        $application->setAutoExit(false);

        self::assertSame('SightPress', $application->getName());
        self::assertSame('1.0.0', $application->getVersion());

        return new ApplicationTester($application);
    }

    /**
     * @param \Closure(ModuleRegistrar): void ...$registers one module each
     */
    private function registry(\Closure ...$registers): ModuleRegistry
    {
        $candidates = [];
        foreach (array_values($registers) as $index => $register) {
            $candidates[] = new ModuleCandidate(
                new FakeModule(['first', 'second'][$index] ?? 'other', register: $register),
                'sightpress/core',
            );
        }

        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue($candidates)->resolve(new ModuleSelection()));
    }
}

final class SameNameCommand implements Command
{
    public function name(): string
    {
        return 'example:echo';
    }

    public function description(): string
    {
        return 'Clashes with the example command.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        return self::SUCCESS;
    }
}
