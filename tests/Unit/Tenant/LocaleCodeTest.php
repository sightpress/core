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

    #[TestWith(['en-GB', 'en-GB'])]
    #[TestWith([' pt_br ', 'pt-BR'])]
    #[TestWith(['EN_gb', 'en-GB'])]
    #[TestWith(['CY', 'cy'])]
    #[TestWith(['zh_HANT_tw', 'zh-Hant-TW'])]
    #[TestWith(['sr-latn', 'sr-Latn'])]
    public function testWritesALocaleTheSightPressWayWhateverItsCaseAndSeparator(string $typed, string $locale): void
    {
        self::assertSame($locale, LocaleCode::normalise($typed));
    }

    #[TestWith(['english'])]
    #[TestWith(['xx'])]
    #[TestWith(['en-QQ'])]
    #[TestWith(['en-Xxxx-GB'])]
    #[TestWith(['en--GB'])]
    #[TestWith([''])]
    public function testRefusesWhatIsNotALocale(string $typed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a locale: use one such as en-GB or pt-BR.', $typed));

        LocaleCode::normalise($typed);
    }

    #[TestWith(['en-GB', 'British English'])]
    #[TestWith(['pt-BR', 'Brazilian Portuguese'])]
    #[TestWith(['pt-PT', 'European Portuguese'])]
    #[TestWith(['cy', 'Welsh'])]
    #[TestWith(['sr-Latn', 'Serbian (Latin)'])]
    public function testNamesALocaleAsPeopleSayIt(string $locale, string $name): void
    {
        self::assertSame($name, LocaleCode::describe($locale));
    }
}
