<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use SightPress\Core\Tests\PHPStan\ModuleBoundaryRule;

/**
 * The architecture test of ADR-0009: proves on fixture modules that the PHPStan rule run by `composer phpstan`
 * stops a module from using another module's internals or the module loader.
 *
 * @extends RuleTestCase<ModuleBoundaryRule>
 */
#[CoversNothing]
final class ModuleBoundaryRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../PHPStan/Fixtures/ModuleBoundary';

    /** The fixtures stand in for `SightPress\Core\` under this namespace. */
    private const string ROOT = 'SightPress\\Core\\Tests\\PHPStan\\Fixtures\\ModuleBoundary\\';

    protected function getRule(): Rule
    {
        return new ModuleBoundaryRule($this->createReflectionProvider(), self::ROOT);
    }

    public function testAModuleMayOnlyUseAnotherModuleThroughItsContract(): void
    {
        $profile = self::ROOT . 'User\\ProfileService (module User)';

        $this->analyse($this->fixtures(), [
            [
                $profile . ' must not use ' . self::ROOT . 'Page\\Internal\\PageRepository, an internal class of module '
                    . 'Page. Use a public service from ' . self::ROOT . 'Page\\Contract\\ instead, and require that module.',
                22,
            ],
            [
                $profile . ' must not use the module loader (' . self::ROOT . 'Module\\ModuleRegistry); '
                    . 'register through the SightPress\\Module contracts.',
                30,
            ],
            [
                $profile . ' must not use ' . self::ROOT . 'Page\\PageModule, an internal class of module Page. '
                    . 'Use a public service from ' . self::ROOT . 'Page\\Contract\\ instead, and require that module.',
                40,
            ],
        ]);
    }

    public function testCoreCodeAndTheContractsAreNotRestricted(): void
    {
        $this->analyse([self::FIXTURES . '/Http/PageController.php', self::FIXTURES . '/Page/Internal/PageRepository.php'], []);
    }

    /**
     * @return list<string>
     */
    private function fixtures(): array
    {
        return [
            self::FIXTURES . '/Page/PageModule.php',
            self::FIXTURES . '/Page/Contract/PageFinder.php',
            self::FIXTURES . '/Page/Internal/PageRepository.php',
            self::FIXTURES . '/User/UserModule.php',
            self::FIXTURES . '/User/ProfileService.php',
            self::FIXTURES . '/Http/PageController.php',
            self::FIXTURES . '/Module/ModuleRegistry.php',
        ];
    }
}
