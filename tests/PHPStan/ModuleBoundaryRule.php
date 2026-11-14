<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Architecture rule (ADR-0009): a core module doesn't reach into another module's internals or the module loader.
 *
 * A core context is a module when it has a `SightPress\Core\<Context>\<Context>Module` class. Code in a module may
 * use its own classes, the `SightPress\Module\` contracts, core's shared code (kernel, HTTP, infrastructure …) and
 * another module's `SightPress\Core\<Other>\Contract\` namespace — nothing else of another module, and nothing of
 * `SightPress\Core\Module\` (the loader), so core modules have no back door that an external module couldn't use.
 *
 * @implements Rule<InClassNode>
 */
final readonly class ModuleBoundaryRule implements Rule
{
    /**
     * @param string $root namespace of core, with a trailing backslash (tests point it at fixtures)
     */
    public function __construct(
        private ReflectionProvider $reflection,
        private string $root = 'SightPress\\Core\\',
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();
        $own = $this->moduleOf($class);
        if ($own === null) {
            return [];
        }

        $errors = [];
        $reported = [];
        foreach (new NodeFinder()->findInstanceOf($node->getOriginalNode(), Name::class) as $name) {
            $target = $this->resolve($name);
            if ($target === null || isset($reported[$target])) {
                continue;
            }

            $message = $this->violation($class, $own, $target);
            if ($message !== null) {
                $reported[$target] = true;
                $errors[] = RuleErrorBuilder::message($message)
                    ->identifier('sightpress.moduleBoundary')
                    ->line($name->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    private function violation(string $class, string $own, string $target): ?string
    {
        if (str_starts_with($target, $this->root . 'Module\\')) {
            return sprintf(
                '%s (module %s) must not use the module loader (%s); register through the SightPress\Module contracts.',
                $class,
                $own,
                $target,
            );
        }

        $other = $this->moduleOf($target);
        if ($other === null || $other === $own || str_starts_with($target, $this->root . $other . '\\Contract\\')) {
            return null;
        }

        return sprintf(
            '%s (module %s) must not use %s, an internal class of module %s. '
            . 'Use a public service from %s%s\\Contract\\ instead, and require that module.',
            $class,
            $own,
            $target,
            $other,
            $this->root,
            $other,
        );
    }

    /**
     * The context of a core class when that context is a module, for example `Page` for `SightPress\Core\Page\X`.
     */
    private function moduleOf(string $class): ?string
    {
        if (!str_starts_with($class, $this->root)) {
            return null;
        }

        $parts = explode('\\', substr($class, strlen($this->root)));
        if (count($parts) < 2) {
            return null;
        }

        $context = $parts[0];

        return $this->reflection->hasClass($this->root . $context . '\\' . $context . 'Module') ? $context : null;
    }

    private function resolve(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        return $name instanceof Name\FullyQualified ? $name->toString() : null;
    }
}
