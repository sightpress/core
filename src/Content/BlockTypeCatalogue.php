<?php

declare(strict_types=1);

namespace SightPress\Core\Content;

use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Block\BlockType;
use UnexpectedValueException;

/**
 * Every block type the modules that are on registered, built once, with the module that registered each one. The
 * block module validates and renders blocks with it.
 */
final readonly class BlockTypeCatalogue
{
    public const string NAME_PATTERN = '/^(?:[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /** @var array<string, array{module: string, type: BlockType}> */
    private array $types;

    /**
     * @param list<array{module: string, type: BlockType}> $types
     */
    public function __construct(array $types = [])
    {
        $byName = [];
        foreach ($types as $entry) {
            $name = $entry['type']->name();
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new UnexpectedValueException(sprintf(
                    'Block type "%s" of module "%s" needs a name of lower-case words joined by "_", with a vendor prefix and a dot for other modules, for example "acme.event_card".',
                    $name,
                    $entry['module'],
                ));
            }
            if (isset($byName[$name])) {
                throw new UnexpectedValueException(sprintf(
                    'Block type "%s" is registered by both module "%s" and module "%s".',
                    $name,
                    $byName[$name]['module'],
                    $entry['module'],
                ));
            }
            $byName[$name] = $entry;
        }
        ksort($byName);
        $this->types = $byName;
    }

    /**
     * Builds every registered block type from the container.
     */
    public static function fromRegistry(ModuleRegistry $registry, ContainerInterface $container): self
    {
        $types = [];
        foreach ($registry->blockTypes() as $registered) {
            $type = $container->get($registered->item);
            if (!$type instanceof BlockType) {
                throw new UnexpectedValueException(sprintf('Block type %s of module "%s" could not be built.', $registered->item, $registered->module));
            }
            $types[] = ['module' => $registered->module, 'type' => $type];
        }

        return new self($types);
    }

    public function get(string $name): ?BlockType
    {
        return $this->types[$name]['type'] ?? null;
    }

    /**
     * @return array<string, BlockType> keyed by name, sorted
     */
    public function all(): array
    {
        return array_map(static fn (array $entry): BlockType => $entry['type'], $this->types);
    }

    public function moduleOf(string $name): ?string
    {
        return $this->types[$name]['module'] ?? null;
    }
}
