<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tests\Support\PageAdminFixture;

#[CoversClass(EnabledModules::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class EnabledModulesTest extends TestCase
{
    public function testSaysWhichModulesAreOn(): void
    {
        $modules = PageAdminFixture::modules('search', 'acme.events');

        self::assertTrue($modules->has('search'));
        self::assertTrue($modules->has('acme.events'));
        self::assertFalse($modules->has('analytics'));
    }
}
