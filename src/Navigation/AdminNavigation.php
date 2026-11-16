<?php

declare(strict_types=1);

namespace SightPress\Core\Navigation;

use LogicException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Admin\AdminMenuItem;

/**
 * The admin navigation, built from the entries the modules that are on registered. Each person sees only the
 * entries whose permission they hold (and none below a parent they can't see), sorted by position and label, with
 * the entry for the current screen marked.
 */
final readonly class AdminNavigation
{
    /**
     * @param list<AdminMenuItem>   $items
     * @param array<string, string> $paths route name => path, for every route an entry links to
     */
    public function __construct(private array $items = [], private array $paths = [])
    {
        foreach ($items as $item) {
            $path = $paths[$item->route] ?? throw new LogicException(sprintf(
                'Admin menu entry "%s" links to route "%s", which no module that is on registered.',
                $item->id,
                $item->route,
            ));
            if (str_contains($path, '{')) {
                throw new LogicException(sprintf(
                    'Admin menu entry "%s" links to route "%s", whose path %s needs values; link to a path without placeholders.',
                    $item->id,
                    $item->route,
                    $path,
                ));
            }
        }
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        $paths = [];
        foreach ($registry->routes() as $registered) {
            $paths[$registered->item->name] = $registered->item->path;
        }

        return new self(
            array_map(static fn ($registered): AdminMenuItem => $registered->item, $registry->adminMenu()),
            $paths,
        );
    }

    /**
     * The entries someone with these permissions sees, as a tree. The current entry is the one whose path is the
     * longest match for the path of the screen: `/admin/users/invite` marks "Users" (`/admin/users`).
     *
     * @param list<string> $permissions
     *
     * @return list<AdminNavigationEntry>
     */
    public function for(array $permissions, string $currentPath): array
    {
        $granted = array_fill_keys($permissions, true);
        $visible = array_values(array_filter(
            $this->items,
            static fn (AdminMenuItem $item): bool => $item->permission === null || isset($granted[$item->permission]),
        ));
        usort($visible, static fn (AdminMenuItem $a, AdminMenuItem $b): int => [$a->position, $a->label] <=> [$b->position, $b->label]);

        $current = null;
        $longest = -1;
        foreach ($visible as $item) {
            $path = $this->paths[$item->route];
            if (self::matches($path, $currentPath) && strlen($path) > $longest) {
                $current = $item->id;
                $longest = strlen($path);
            }
        }

        return $this->children(null, $visible, $current);
    }

    /**
     * @param list<AdminMenuItem> $items
     *
     * @return list<AdminNavigationEntry>
     */
    private function children(?string $parent, array $items, ?string $current): array
    {
        $entries = [];
        foreach ($items as $item) {
            if ($item->parent === $parent) {
                $entries[] = new AdminNavigationEntry(
                    $item->id,
                    $item->label,
                    $this->paths[$item->route],
                    $item->id === $current,
                    $this->children($item->id, $items, $current),
                );
            }
        }

        return $entries;
    }

    private static function matches(string $path, string $currentPath): bool
    {
        $path = rtrim($path, '/');
        $currentPath = rtrim($currentPath, '/');

        return $currentPath === $path || str_starts_with($currentPath, $path . '/');
    }
}
