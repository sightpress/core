<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Assistant;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\Assistant\FakeProvider;
use SightPress\Core\Tests\Support\Assistant\FakeTask;
use SightPress\Core\Tests\Support\Assistant\FakeTaskWithVision;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Module\ModuleRegistrar;

#[CoversClass(AssistantExtensions::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantExtensionsTest extends TestCase
{
    public function testBuildsTheProvidersAndTasksOfTheModulesThatAreOnOnce(): void
    {
        $provider = new FakeProvider();
        $extensions = new AssistantExtensions(
            new ArrayContainer([FakeProvider::class => $provider, FakeTask::class => new FakeTask(), FakeTaskWithVision::class => new FakeTaskWithVision()]),
            self::registry([FakeProvider::class], [FakeTask::class, FakeTaskWithVision::class]),
        );

        self::assertSame(['fake' => $provider], $extensions->providers());
        self::assertSame($provider, $extensions->provider('fake'));
        self::assertNull($extensions->provider('other'));
        self::assertSame(['fake_task', 'fake_vision_task'], array_keys($extensions->tasks()));
        self::assertSame('fake_task', $extensions->task('fake_task')?->id());
        self::assertNull($extensions->task('other'));
        self::assertSame($extensions->tasks(), $extensions->tasks());
    }

    public function testRefusesWhatIsNotAProviderOrTask(): void
    {
        $extensions = new AssistantExtensions(
            new ArrayContainer([FakeProvider::class => new \stdClass(), FakeTask::class => new \stdClass()]),
            self::registry([FakeProvider::class], [FakeTask::class]),
        );

        foreach ([static fn () => $extensions->providers(), static fn () => $extensions->tasks()] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('of module "acme.ai" is not a', $exception->getMessage());
            }
        }
    }

    public function testRefusesTwoWithTheSameId(): void
    {
        $extensions = new AssistantExtensions(
            new ArrayContainer([FakeProvider::class => new FakeProvider(), AnthropicProvider::class => new FakeProvider(), FakeTask::class => new FakeTask('same'), FakeTaskWithVision::class => new FakeTask('same')]),
            self::registry([FakeProvider::class, AnthropicProvider::class], [FakeTask::class, FakeTaskWithVision::class]),
        );

        foreach ([[static fn () => $extensions->providers(), 'uses the id "fake", which ' . FakeProvider::class], [static fn () => $extensions->tasks(), 'uses the id "same", which ' . FakeTask::class]] as [$call, $message]) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (LogicException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /**
     * @param list<class-string<\SightPress\Module\Assistant\AssistantProvider>> $providers
     * @param list<class-string<\SightPress\Module\Assistant\AssistantTask>>     $tasks
     */
    private static function registry(array $providers, array $tasks): ModuleRegistry
    {
        $module = new FakeModule('acme.ai', register: static function (ModuleRegistrar $registrar) use ($providers, $tasks): void {
            foreach ($providers as $provider) {
                $registrar->assistant()->addProvider($provider);
            }
            foreach ($tasks as $task) {
                $registrar->assistant()->addTask($task);
            }
        });

        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/ai')])->resolve(new ModuleSelection()));
    }
}
