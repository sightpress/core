<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Search;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Search\SearchExtensions;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Search\FakeSearchProvider;
use SightPress\Core\Tests\Support\Search\RecordingSearchBackend;
use SightPress\Module\ModuleRegistrar;
use stdClass;

#[CoversClass(SearchExtensions::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class SearchExtensionsTest extends TestCase
{
    public function testBuildsTheProvidersAndTheBackendModulesRegistered(): void
    {
        $events = new FakeSearchProvider();
        $news = new FakeSearchProvider('acme.news');
        $backend = new RecordingSearchBackend();
        $extensions = new SearchExtensions(
            new ArrayContainer([FakeSearchProvider::class => $events, NewsProvider::class => $news, RecordingSearchBackend::class => $backend]),
            self::registry(static function (ModuleRegistrar $registrar): void {
                $registrar->search()->addProvider(FakeSearchProvider::class);
                $registrar->search()->addProvider(NewsProvider::class);
                $registrar->search()->useBackend(RecordingSearchBackend::class);
            }),
        );

        self::assertSame(['acme.events' => $events, 'acme.news' => $news], $extensions->providers());
        self::assertSame($extensions->providers(), $extensions->providers());
        self::assertSame($news, $extensions->provider('acme.news'));
        self::assertNull($extensions->provider('page'));
        self::assertSame($backend, $extensions->backend());
        self::assertNull(new SearchExtensions(new ArrayContainer(), self::registry(static function (): void {
        }))->backend(), 'core\'s own back-end');
    }

    public function testRefusesWhatIsNotAProviderOrABackend(): void
    {
        $register = static function (ModuleRegistrar $registrar): void {
            $registrar->search()->addProvider(FakeSearchProvider::class);
            $registrar->search()->useBackend(RecordingSearchBackend::class);
        };
        $broken = new SearchExtensions(new ArrayContainer([FakeSearchProvider::class => new stdClass(), RecordingSearchBackend::class => new stdClass()]), self::registry($register));

        try {
            $broken->providers();
            self::fail('not a provider');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Search document provider ' . FakeSearchProvider::class . ' of module "acme" is not a', $exception->getMessage());
        }
        $this->expectExceptionMessage('Search back-end ' . RecordingSearchBackend::class . ' of module "acme" is not a');
        $broken->backend();
    }

    public function testTwoProvidersMayNotShareASource(): void
    {
        $extensions = new SearchExtensions(
            new ArrayContainer([FakeSearchProvider::class => new FakeSearchProvider(), NewsProvider::class => new FakeSearchProvider()]),
            self::registry(static function (ModuleRegistrar $registrar): void {
                $registrar->search()->addProvider(FakeSearchProvider::class);
                $registrar->search()->addProvider(NewsProvider::class);
            }),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uses the source "acme.events", which ' . FakeSearchProvider::class . ' already uses.');
        $extensions->providers();
    }

    /**
     * @param Closure(ModuleRegistrar): void $register
     */
    private static function registry(Closure $register): ModuleRegistry
    {
        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme', register: $register), 'acme/search'),
        ])->resolve(new ModuleSelection()));
    }
}

/**
 * A second provider class (any SearchDocumentProvider works as an id).
 */
abstract class NewsProvider implements \SightPress\Module\Search\SearchDocumentProvider
{
}
