<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

use SightPress\Core\Template\Message;

/**
 * Groups permissions by what they are about, for the roles screen: the part of the name before the first dot
 * (`page.publish` is about pages). Known areas come first, in the order of the admin menu; others follow by name.
 */
final class PermissionAreas
{
    private const array ORDER = ['admin', 'page', 'media', 'menu', 'user', 'role', 'settings', 'module', 'theme', 'analytics', 'assistant', 'audit'];

    public static function of(string $permission): string
    {
        $area = strtolower(explode('.', $permission)[0]);

        $area = trim((string) preg_replace('/[^a-z0-9]+/', '_', $area), '_');

        return $area === '' ? 'other' : $area;
    }

    public static function label(string $area): Message
    {
        return match ($area) {
            'admin' => new Message('Admin panel'),
            'page' => new Message('Pages'),
            'media' => new Message('Media'),
            'menu' => new Message('Menus'),
            'user' => new Message('People'),
            'role' => new Message('Roles'),
            'settings' => new Message('Site settings'),
            'module' => new Message('Features'),
            'theme' => new Message('Appearance'),
            'analytics' => new Message('Analytics'),
            'assistant' => new Message('AI assistant'),
            'audit' => new Message('Audit log'),
            default => new Message('Other: {area}', ['area' => $area]),
        };
    }

    /**
     * Sorts area keys: known ones in their order, then the rest by name.
     *
     * @param list<string> $areas
     *
     * @return list<string>
     */
    public static function sort(array $areas): array
    {
        $order = array_flip(self::ORDER);
        usort($areas, static fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return $areas;
    }
}
