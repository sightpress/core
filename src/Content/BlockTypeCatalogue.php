<?php

declare(strict_types=1);

namespace SightPress\Core\Content;

use Psr\Container\ContainerInterface;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockType;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\StyledBlockType;
use UnexpectedValueException;

/**
 * Every block type the modules that are on registered, built once, with the module that registered each one, and
 * the looks each type offers: its own variants and those other modules that are on add to it (ADR-0064). The block
 * module validates and renders blocks with it.
 */
final readonly class BlockTypeCatalogue
{
    public const string NAME_PATTERN = '/^(?:[a-z][a-z0-9]*(?:_[a-z0-9]+)*\.)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /** @var array<string, array{module: string, type: BlockType}> */
    private array $types;

    /** @var array<string, BlockStyles> */
    private array $styles;

    /**
     * @param list<array{module: string, type: BlockType}>              $types
     * @param list<array{module: string, type: string, variant: BlockVariant}> $variants added by modules to other modules' types
     */
    public function __construct(array $types = [], array $variants = [])
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
        $this->styles = self::styles($byName, $variants);
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
        $variants = [];
        foreach ($registry->blockVariants() as $registered) {
            $variants[] = ['module' => $registered->module, 'type' => $registered->item['blockType'], 'variant' => $registered->item['variant']];
        }

        return new self($types, $variants);
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

    /**
     * The looks and shared options of a block type, with the variants other modules add; null when it has none.
     */
    public function stylesOf(string $name): ?BlockStyles
    {
        return $this->styles[$name] ?? null;
    }

    /**
     * @param array<string, array{module: string, type: BlockType}>              $types
     * @param list<array{module: string, type: string, variant: BlockVariant}> $variants
     *
     * @return array<string, BlockStyles>
     */
    private static function styles(array $types, array $variants): array
    {
        $styles = [];
        foreach ($types as $name => $entry) {
            if ($entry['type'] instanceof StyledBlockType) {
                $styles[$name] = $entry['type']->styles();
            }
        }
        foreach ($variants as $added) {
            // A variant for a type that isn't on this site, offers no variants or already has one by that name is
            // left out, so another module being off never breaks the site.
            $own = $styles[$added['type']] ?? null;
            $names = array_map(static fn (BlockVariant $variant): string => $variant->name, $own->variants ?? []);
            if ($own !== null && $names !== [] && !in_array($added['variant']->name, $names, true)) {
                $styles[$added['type']] = new BlockStyles([...$own->variants, $added['variant']], $own->options, $own->shortForm);
            }
        }

        return $styles;
    }
}
