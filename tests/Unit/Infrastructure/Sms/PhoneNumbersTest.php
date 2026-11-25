<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Sms\PhoneNumbers;
use SightPress\Module\Sms\PhoneNumber;

#[CoversClass(PhoneNumbers::class)]
final class PhoneNumbersTest extends TestCase
{
    public function testReadsNumbersAsPeopleTypeThem(): void
    {
        $numbers = new PhoneNumbers();
        self::assertSame('+447700900123', $numbers->parse('07700 900123', 'gb')?->e164);
        self::assertSame('+447700900123', $numbers->parse('+44 (0)7700-900123', null)?->e164);
        self::assertSame('+447700900123', $numbers->parse('0044 7700 900123', 'PT')?->e164);
        self::assertSame('+351912345678', $numbers->parse('912 345 678', 'PT')?->e164);
        foreach (['', 'call me', '07700', '+44 7700 900123 999 999 999 999 999 999 999 999', '123'] as $input) {
            self::assertNull($numbers->parse($input, 'GB'), $input);
        }
        self::assertNull($numbers->parse('07700 900123', null), 'a national number needs a country');
    }

    public function testTellsWhichNumbersCanGetTexts(): void
    {
        $numbers = new PhoneNumbers();
        self::assertTrue($numbers->canReceiveTexts(new PhoneNumber('+447400123456')));
        self::assertTrue($numbers->canReceiveTexts(new PhoneNumber('+12015550123')), 'the US does not tell mobile and fixed lines apart');
        foreach (['+442079460000', '+449098790000', '+448001111111', '+447700900123'] as $number) {
            self::assertFalse($numbers->canReceiveTexts(new PhoneNumber($number)), $number);
        }
        self::assertSame(['GB', 'PT', null], [$numbers->country(new PhoneNumber('+447400123456')), $numbers->country(new PhoneNumber('+351912345678')), $numbers->country(new PhoneNumber('+8812345678'))]);
        self::assertSame('07400 123456', $numbers->national(new PhoneNumber('+447400123456')));
    }

    public function testFindsTheCountryOfALocale(): void
    {
        $numbers = new PhoneNumbers();
        self::assertSame(['GB', 'PT', 'ES', 'BR', null, null], [$numbers->regionOf('en-GB'), $numbers->regionOf('pt'), $numbers->regionOf('es'), $numbers->regionOf('pt-BR'), $numbers->regionOf('en'), $numbers->regionOf('und')]);
        $codes = $numbers->callingCodes();
        self::assertSame([44, 351], [$codes['GB'], $codes['PT']]);
        self::assertSame(array_keys($codes), array_values(array_unique(array_keys($codes))));
    }
}
