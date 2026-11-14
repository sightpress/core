<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Container;

use DI\Container;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use stdClass;

#[CoversClass(Services::class)]
final class ServicesTest extends TestCase
{
    public function testItReturnsTheServiceWhenItHasTheExpectedType(): void
    {
        $service = new stdClass();
        $container = new Container(['by-id' => $service, stdClass::class => $service]);

        self::assertSame($service, Services::get($container, stdClass::class));
        self::assertSame($service, Services::get($container, stdClass::class, 'by-id'));
    }

    public function testItRefusesAServiceOfAnotherType(): void
    {
        $container = new Container(['number' => 42]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Service "number" is a int, not a stdClass.');

        Services::get($container, stdClass::class, 'number');
    }
}
