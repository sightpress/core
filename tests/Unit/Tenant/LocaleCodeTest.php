<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\LocaleCode;

#[CoversClass(LocaleCode::class)]
final class LocaleCodeTest extends TestCase
{
    #[TestWith(['en-GB', true])]
    #[TestWith(['cy', true])]
    #[TestWith(['zh-Hant-TW', true])]
    #[TestWith(['en_GB', false])]
    #[TestWith(['EN', false])]
    #[TestWith(['', false])]
    public function testValidatesLocaleCodes(string $locale, bool $valid): void
    {
        self::assertSame($valid, LocaleCode::isValid($locale));
    }
}
