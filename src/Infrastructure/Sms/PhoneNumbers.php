<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Sms;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\ValidationResult;
use Locale;
use SensitiveParameter;
use SightPress\Module\Sms\PhoneNumber;

/**
 * Reads phone numbers the way people type them (N-2, N-3, ADR-0069), with libphonenumber: `07700 900123` in the UK and
 * `+44 7700 900123` are the same {@see PhoneNumber}. Also says, without asking a provider (so for free), whether a
 * number can get text messages at all: only mobile numbers can, never premium-rate, shared-cost or toll-free ones.
 */
final readonly class PhoneNumbers
{
    /** Number types that can get text messages. Some countries don't tell mobile and fixed lines apart. */
    private const array TEXTABLE = [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE];

    private PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    /**
     * The number in E.164 form, or null when it can't be a phone number (wrong length for its country, letters).
     * Without a leading `+` (or `00`), it is read as a number of `$region`. Whether it is a real number that can get
     * texts is {@see canReceiveTexts()}.
     */
    public function parse(#[SensitiveParameter] string $input, ?string $region): ?PhoneNumber
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > 40 || preg_match('/^[+0-9\s().\-\/]+$/', $input) !== 1) {
            return null;
        }
        try {
            $parsed = $this->util->parse($input, $region === null ? null : strtoupper($region));
        } catch (NumberParseException) {
            return null;
        }
        if ($this->util->isPossibleNumberWithReason($parsed) !== ValidationResult::IS_POSSIBLE) {
            return null;
        }

        return new PhoneNumber($this->util->format($parsed, PhoneNumberFormat::E164));
    }

    /**
     * The number's country (ISO 3166-1 alpha-2), or null when it has none (for example a satellite number).
     */
    public function country(PhoneNumber $number): ?string
    {
        $region = $this->util->getRegionCodeForNumber($this->util->parse($number->e164));

        return $region !== null && preg_match('/^[A-Z]{2}$/', $region) === 1 ? $region : null;
    }

    /**
     * Whether the number is a kind that can get text messages: a mobile number (or one the country doesn't tell
     * apart from a fixed line).
     */
    public function canReceiveTexts(PhoneNumber $number): bool
    {
        $parsed = $this->util->parse($number->e164);

        return $this->util->isValidNumber($parsed) && in_array($this->util->getNumberType($parsed), self::TEXTABLE, true);
    }

    /**
     * How the number is written in its own country, for the person who owns it (`07700 900123`).
     */
    public function national(PhoneNumber $number): string
    {
        return $this->util->format($this->util->parse($number->e164), PhoneNumberFormat::NATIONAL);
    }

    /**
     * The country of a locale such as `en-GB` (`GB`); for a locale without one, such as `pt`, the country of the same
     * code when there is one (`PT`), else null.
     */
    public function regionOf(string $locale): ?string
    {
        $region = Locale::getRegion(str_replace('-', '_', $locale));
        if (!is_string($region) || $region === '') {
            $region = strtoupper(Locale::getPrimaryLanguage($locale) ?? '');
        }

        return in_array($region, $this->util->getSupportedRegions(), true) ? $region : null;
    }

    /**
     * Every country libphonenumber knows, with its calling code, for the country select (N-3).
     *
     * @return array<string, int> country => calling code
     */
    public function callingCodes(): array
    {
        $codes = [];
        foreach ($this->util->getSupportedRegions() as $region) {
            $codes[$region] = $this->util->getCountryCodeForRegion($region);
        }
        ksort($codes);

        return $codes;
    }
}
