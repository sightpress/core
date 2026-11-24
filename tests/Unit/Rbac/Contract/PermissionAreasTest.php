<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Contract\PermissionAreas;

#[CoversClass(PermissionAreas::class)]
#[UsesNamespace('SightPress\Core\Template')]
final class PermissionAreasTest extends TestCase
{
    public function testAnAreaIsWhatTheNameStartsWith(): void
    {
        self::assertSame('page', PermissionAreas::of('page.edit.own'));
        self::assertSame('acme_events', PermissionAreas::of('Acme-Events.manage'));
        self::assertSame('other', PermissionAreas::of('.hidden'));
    }

    public function testKnownAreasHaveANameAndComeFirst(): void
    {
        $labels = [];
        foreach (['admin', 'page', 'media', 'menu', 'user', 'role', 'settings', 'module', 'theme', 'analytics', 'assistant', 'audit', 'acme'] as $area) {
            $labels[] = (string) PermissionAreas::label($area);
        }

        self::assertSame(['Admin panel', 'Pages', 'Media', 'Menus', 'People', 'Roles', 'Site settings', 'Features', 'Appearance', 'Analytics', 'AI assistant', 'Audit log', 'Other: acme'], $labels);
        self::assertSame(['admin', 'page', 'audit', 'acme', 'zoo'], PermissionAreas::sort(['zoo', 'audit', 'acme', 'page', 'admin']));
    }
}
