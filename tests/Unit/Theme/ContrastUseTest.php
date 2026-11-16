<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\ContrastUse;

#[CoversClass(ContrastUse::class)]
final class ContrastUseTest extends TestCase
{
    public function testTextNeedsFourAndAHalfAndInterfaceColoursThree(): void
    {
        self::assertSame(4.5, ContrastUse::Text->minimum());
        self::assertSame(3.0, ContrastUse::Ui->minimum());
        self::assertSame('text', ContrastUse::Text->label());
        self::assertSame('interface colour', ContrastUse::Ui->label());
    }
}
