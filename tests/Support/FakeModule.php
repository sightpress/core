<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Closure;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * A module for tests: a manifest built from the arguments and an optional register callback.
 */
final readonly class FakeModule implements ModuleInterface
{
    /**
     * @param array<string, string>                 $requires module id => version constraint
     * @param (Closure(ModuleRegistrar): void)|null $register
     * @param (Closure(): ModuleManifest)|null      $manifest replaces the manifest built from the other arguments
     */
    public function __construct(
        private string $id,
        private array $requires = [],
        private bool $required = false,
        private bool $enabledByDefault = true,
        private string $version = '1.0.0',
        private ?Closure $register = null,
        private ?Closure $manifest = null,
    ) {
    }

    public function manifest(): ModuleManifest
    {
        if ($this->manifest !== null) {
            return ($this->manifest)();
        }

        $requirements = [];
        foreach ($this->requires as $id => $constraint) {
            $requirements[] = new ModuleRequirement($id, $constraint);
        }

        return new ModuleManifest($this->id, $this->version, $requirements, $this->required, $this->enabledByDefault);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        if ($this->register !== null) {
            ($this->register)($registrar);
        }
    }
}
